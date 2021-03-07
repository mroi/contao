<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\Database\Result;
use Patchwork\Utf8;

/**
 * Creates and queries the search index
 *
 * The class takes the HTML markup of a page, exctracts the content and writes
 * it to the database (search index). It also provides a method to query the
 * seach index, returning the matching entries.
 *
 * Usage:
 *
 *     Search::indexPage($objPage->row());
 *     $result = Search::searchFor('keyword');
 *
 *     while ($result->next())
 *     {
 *         echo $result->url;
 *     }
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Search
{
	/**
	 * Object instance (Singleton)
	 * @var Search
	 */
	protected static $objInstance;

	/**
	 * Index a page
	 *
	 * @param array $arrData The data array
	 *
	 * @return boolean True if a new record was created
	 */
	public static function indexPage($arrData)
	{
		$objDatabase = Database::getInstance();

		$arrSet['tstamp'] = time();
		$arrSet['url'] = $arrData['url'];
		$arrSet['title'] = $arrData['title'];
		$arrSet['protected'] = $arrData['protected'];
		$arrSet['filesize'] = $arrData['filesize'] ?? null;
		$arrSet['groups'] = $arrData['groups'];
		$arrSet['pid'] = $arrData['pid'];
		$arrSet['language'] = $arrData['language'];

		// Get the file size from the raw content
		if (!$arrSet['filesize'])
		{
			$arrSet['filesize'] = number_format((\strlen($arrData['content']) / 1024), 2, '.', '');
		}

		// Replace special characters
		$strContent = str_replace(array("\n", "\r", "\t", '&#160;', '&nbsp;', '&shy;'), array(' ', ' ', ' ', ' ', ' ', ''), $arrData['content']);

		// Strip script tags
		while (($intStart = strpos($strContent, '<script')) !== false)
		{
			if (($intEnd = strpos($strContent, '</script>', $intStart)) !== false)
			{
				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
			}
			else
			{
				break; // see #5119
			}
		}

		// Strip style tags
		while (($intStart = strpos($strContent, '<style')) !== false)
		{
			if (($intEnd = strpos($strContent, '</style>', $intStart)) !== false)
			{
				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 8);
			}
			else
			{
				break; // see #5119
			}
		}

		// Strip non-indexable areas
		while (($intStart = strpos($strContent, '<!-- indexer::stop -->')) !== false)
		{
			if (($intEnd = strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false)
			{
				$intCurrent = $intStart;

				// Handle nested tags
				while (($intNested = strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd)
				{
					if (($intNewEnd = strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false)
					{
						$intEnd = $intNewEnd;
						$intCurrent = $intNested;
					}
					else
					{
						break; // see #5119
					}
				}

				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 26);
			}
			else
			{
				break; // see #5119
			}
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['indexPage']) && \is_array($GLOBALS['TL_HOOKS']['indexPage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['indexPage'] as $callback)
			{
				System::importStatic($callback[0])->{$callback[1]}($strContent, $arrData, $arrSet);
			}
		}

		// Free the memory
		unset($arrData['content']);

		$arrMatches = array();
		preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE);
		$intOffset = \strlen($arrMatches[0][0]) + $arrMatches[0][1];

		// Split page in head and body section
		$strHead = substr($strContent, 0, $intOffset);
		$strBody = substr($strContent, $intOffset);

		unset($strContent);

		$tags = array();

		// Get the description
		if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
		{
			$arrData['description'] = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($tags[1])));
		}

		// Get the keywords
		if (preg_match('/<meta[^>]+name="keywords"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
		{
			$arrData['keywords'] = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($tags[1])));
		}
		else
		{
			$arrData['keywords'] = '';
		}

		// Read the title and alt attributes
		if (preg_match_all('/<* (title|alt)="([^"]*)"[^>]*>/i', $strBody, $tags))
		{
			$arrData['keywords'] .= ' ' . implode(', ', array_unique($tags[2]));
		}

		// Add a whitespace character before line-breaks and between consecutive tags (see #5363)
		$strBody = str_ireplace(array('<br', '><'), array(' <br', '> <'), $strBody);
		$strBody = strip_tags($strBody);

		// Put everything together
		$arrSet['text'] = $arrData['title'] . ' ' . $arrData['description'] . ' ' . $strBody . ' ' . $arrData['keywords'];
		$arrSet['text'] = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($arrSet['text'])));

		// Calculate the checksum
		$arrSet['checksum'] = md5($arrSet['text']);

		$blnIndexExists = $objDatabase
			->prepare("SELECT EXISTS(SELECT id FROM tl_search WHERE checksum=? AND pid=? AND url=?) as indexExists")
			->execute($arrSet['checksum'], $arrSet['pid'], $arrSet['url'])
			->indexExists;

		// The page has already been indexed and has not changed (see #2235)
		if ($blnIndexExists)
		{
			return false;
		}

		// Prevent deadlocks
		$objDatabase->query("LOCK TABLES tl_search WRITE, tl_search_index WRITE, tl_search_term WRITE");

		$objIndex = $objDatabase->prepare("SELECT id, url FROM tl_search WHERE checksum=? AND pid=?")
								->limit(1)
								->execute($arrSet['checksum'], $arrSet['pid']);

		if ($objIndex->numRows)
		{
			// The new URL is more canonical (shorter and/or less fragments)
			if (self::compareUrls($arrSet['url'], $objIndex->url) < 0)
			{
				self::removeEntry($arrSet['url']);

				$objDatabase->prepare("UPDATE tl_search %s WHERE id=?")
							->set($arrSet)
							->execute($objIndex->id);
			}

			$objDatabase->query("UNLOCK TABLES");

			// The same page has been indexed under a different URL already (see #8460)
			return false;
		}

		$objIndex = $objDatabase->prepare("SELECT id FROM tl_search WHERE url=?")
								->limit(1)
								->execute($arrSet['url']);

		// Add the page to the tl_search table
		if ($objIndex->numRows)
		{
			$objDatabase->prepare("UPDATE tl_search %s WHERE id=?")
						->set($arrSet)
						->execute($objIndex->id);

			$intInsertId = $objIndex->id;
		}
		else
		{
			$objInsertStmt = $objDatabase->prepare("INSERT INTO tl_search %s")
										 ->set($arrSet)
										 ->execute();

			$intInsertId = $objInsertStmt->insertId;
		}

		// Remove quotes
		$strText = str_replace(array('´', '`'), "'", $arrSet['text']);

		unset($arrSet);

		// Split words
		$arrWords = self::splitIntoWords(Utf8::strtolower($strText), $arrData['language']);
		$arrIndex = array();

		// Index words
		foreach ($arrWords as $strWord)
		{
			if (isset($arrIndex[$strWord]))
			{
				$arrIndex[$strWord]++;
				continue;
			}

			$arrIndex[$strWord] = 1;
		}

		// Decrement document frequency counts
		$objDatabase
			->prepare("
				UPDATE tl_search_term
				INNER JOIN tl_search_index ON tl_search_term.id = tl_search_index.termId AND tl_search_index.pid = ?
				SET documentFrequency = GREATEST(1, documentFrequency) - 1
			")
			->execute($intInsertId);

		// Remove the existing index
		$objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
					->execute($intInsertId);

		// Add new terms and increment frequency counts of existing terms
		$objDatabase
			->prepare("
				INSERT INTO tl_search_term (term, documentFrequency)
				VALUES " . implode(', ', array_fill(0, \count($arrIndex), '(?, 1)')) . "
				ON DUPLICATE KEY UPDATE documentFrequency = documentFrequency + 1
			")
			->execute(array_map('strval', array_keys($arrIndex)));

		// Remove obsolete terms
		$objDatabase->query("DELETE FROM tl_search_term WHERE documentFrequency = 0");

		$objTermIds = $objDatabase
			->prepare("
				SELECT term, id AS termId
				FROM tl_search_term
				WHERE term IN (" . implode(',', array_fill(0, \count($arrIndex), '?')) . ")
			")
			->execute(array_map('strval', array_keys($arrIndex)));

		$arrTermIds = array();

		foreach ($objTermIds->fetchAllAssoc() as $arrTermId)
		{
			$arrTermIds[$arrTermId['term']] = (int) $arrTermId['termId'];
		}

		$arrQuery = array();
		$arrValues = array();

		foreach ($arrIndex as $k=>$v)
		{
			if (empty($arrTermIds[$k]))
			{
				continue;
			}

			$arrQuery[] = '(?, ?, ?)';
			$arrValues[] = $intInsertId;
			$arrValues[] = $arrTermIds[$k];
			$arrValues[] = $v;
		}

		// Create the new index
		$objDatabase->prepare("INSERT INTO tl_search_index (pid, termId, relevance) VALUES " . implode(', ', $arrQuery))
					->execute($arrValues);

		$row = $objDatabase->query("SELECT IFNULL(MIN(id), 0), IFNULL(MAX(id), 0), COUNT(*) FROM tl_search")->fetchRow();

		list($intMinId, $intMaxId, $intCount) = array_map('intval', $row);

		// If the whole corpus has few documents we want to update the vector length of all documents
		if ($intCount <= 200)
		{
			$arrRandomIds = $objDatabase->query("SELECT id FROM tl_search")->fetchEach('id');
		}

		// Otherwise we select approximately 100 random documents that get updated
		else
		{
			$arrRandomIds = array();

			while (\count($arrRandomIds) < ($intMaxId - $intMinId) / $intCount * 100)
			{
				$arrRandomIds[random_int($intMinId, $intMaxId)] = true;
			}

			$arrRandomIds = array_keys($arrRandomIds);
		}

		$arrDocumentIds = array_merge(array($intInsertId), $arrRandomIds);

		// Set or update vector length
		$objDatabase->query("
			UPDATE tl_search
			INNER JOIN (
				SELECT
					tl_search_index.pid,
					SQRT(SUM(POW(
						(1 + LOG(relevance)) * LOG((
							" . ($objDatabase->query("SELECT COUNT(*) as count FROM tl_search")->count + 1) . "
						) / GREATEST(1, documentFrequency)),
						2
					))) as vectorLength
				FROM tl_search_index
				JOIN tl_search_term
					ON tl_search_index.termId = tl_search_term.id
				WHERE tl_search_index.pid IN (" . implode(',', array_map('intval', $arrDocumentIds)) . ")
				GROUP BY tl_search_index.pid
			) si ON si.pid = tl_search.id
			SET tl_search.vectorLength = si.vectorLength
		");

		$objDatabase->query("UNLOCK TABLES");

		return true;
	}

	/**
	 * @return string[]
	 */
	private static function splitIntoWords(string $strText, string $strLocale)
	{
		$iterator = \IntlRuleBasedBreakIterator::createWordInstance($strLocale);
		$iterator->setText($strText);

		$words = array();

		foreach ($iterator->getPartsIterator() as $part)
		{
			if ($iterator->getRuleStatus() !== \IntlBreakIterator::WORD_NONE)
			{
				$words[] = $part;
			}
		}

		return $words;
	}

	/**
	 * Search the index and return the result object
	 *
	 * @param string  $strKeywords  The keyword string
	 * @param boolean $blnOrSearch  If true, the result can contain any keyword
	 * @param array   $arrPid       An optional array of page IDs to limit the result to
	 * @param integer $intRows      An optional maximum number of result rows
	 * @param integer $intOffset    An optional result offset
	 * @param boolean $blnFuzzy     If true, the search will be fuzzy
	 * @param integer $intMinlength Ignore keywords deceeding the minimum length
	 *
	 * @return Result The database result object
	 *
	 * @throws \Exception If the cleaned keyword string is empty
	 */
	public static function searchFor($strKeywords, $blnOrSearch=false, $arrPid=array(), $intRows=0, $intOffset=0, $blnFuzzy=false, $intMinlength=0)
	{
		// Clean the keywords
		$strKeywords = StringUtil::decodeEntities($strKeywords);
		$strKeywords = Utf8::strtolower($strKeywords);

		// Check keyword string
		if (!\strlen($strKeywords))
		{
			throw new \Exception('Empty keyword string');
		}

		// Split keywords
		$arrChunks = array();
		preg_match_all('/"[^"]+"|[+-]?[^ ]+\*?/', $strKeywords, $arrChunks);

		$arrPhrases = array();
		$arrKeywords = array();
		$arrWildcards = array();
		$arrIncluded = array();
		$arrExcluded = array();

		foreach (array_unique($arrChunks[0]) as $strKeyword)
		{
			if (substr($strKeyword, -1) == '*' && \strlen($strKeyword) > 1)
			{
				$arrWildcards[] = str_replace('*', '%', $strKeyword);
				continue;
			}

			switch (substr($strKeyword, 0, 1))
			{
				// Phrases
				case '"':
					if ($strKeyword = trim(substr($strKeyword, 1, -1)))
					{
						$arrPhrases[] = str_replace(' ', '[^[:alnum:]]+', preg_quote($strKeyword));
					}
					break;

				// Included keywords
				case '+':
					if ($strKeyword = trim(substr($strKeyword, 1)))
					{
						foreach (self::splitIntoWords($strKeyword, $GLOBALS['TL_LANGUAGE']) as $strWord)
						{
							$arrIncluded[] = $strWord;
						}
					}
					break;

				// Excluded keywords
				case '-':
					if ($strKeyword = trim(substr($strKeyword, 1)))
					{
						foreach (self::splitIntoWords($strKeyword, $GLOBALS['TL_LANGUAGE']) as $strWord)
						{
							$arrExcluded[] = $strWord;
						}
					}
					break;

				// Wildcards
				case '*':
					if (\strlen($strKeyword) > 1)
					{
						$arrWildcards[] = str_replace('*', '%', $strKeyword);
					}
					break;

				// Normal keywords
				default:
					foreach (self::splitIntoWords($strKeyword, $GLOBALS['TL_LANGUAGE']) as $strWord)
					{
						if ($intMinlength > 0 && \strlen($strWord) < $intMinlength)
						{
							continue;
						}

						$arrKeywords[] = $strWord;
					}
					break;
			}
		}

		// Fuzzy search
		if ($blnFuzzy)
		{
			foreach ($arrKeywords as $strKeyword)
			{
				$arrWildcards[] = '%' . $strKeyword . '%';
			}

			$arrKeywords = array();
		}

		$strQuery = "SELECT *, similarity / vectorLength AS relevance FROM (SELECT tl_search_index.pid AS sid";

		// Remember found terms so we can highlight them later
		$strQuery .= ", GROUP_CONCAT(matchedTerm.term) AS matches";

		$arrValues = array();
		$arrAllKeywords = array();
		$arrMatches = array();
		$arrRequiredMatches = array();
		$arrExcludedMatches = array();

		// Get wildcards
		foreach ($arrWildcards as $strKeyword)
		{
			$arrMatches[] = \count($arrAllKeywords);
			$arrAllKeywords[] = 'term LIKE ?';
			$arrValues[] = $strKeyword;
		}

		// Get keywords
		foreach ($arrKeywords as $strKeyword)
		{
			$arrMatches[] = \count($arrAllKeywords);
			$arrAllKeywords[] = 'term=?';
			$arrValues[] = $strKeyword;
		}

		// Get included keywords
		foreach ($arrIncluded as $strKeyword)
		{
			$arrRequiredMatches[] = \count($arrAllKeywords);
			$arrAllKeywords[] = 'term=?';
			$arrValues[] = $strKeyword;
		}

		// Get excluded keywords
		foreach ($arrExcluded as $strKeyword)
		{
			$arrExcludedMatches[] = \count($arrAllKeywords);
			$arrAllKeywords[] = 'term=?';
			$arrValues[] = $strKeyword;
		}

		// Get keywords from phrases
		foreach ($arrPhrases as $strPhrase)
		{
			foreach (self::splitIntoWords(str_replace('[^[:alnum:]]+', ' ', $strPhrase), $GLOBALS['TL_LANGUAGE']) as $strKeyword)
			{
				$arrMatches[] = \count($arrAllKeywords);
				$arrAllKeywords[] = 'term=?';
				$arrValues[] = $strKeyword;
			}
		}

		// Get the relevance
		$strQuery .= ", (0";

		foreach ($arrAllKeywords as $index => $strKeywordExpression)
		{
			if (\in_array($index, $arrExcludedMatches, true))
			{
				continue;
			}

			if (isset($arrWildcards[$index]))
			{
				$strQuery .= "+ ((1+LOG(SUM(match$index * tl_search_index.relevance))) * POW(LOG((@searchCount + 1) / @wildcardCount$index), 2) / " . (\count($arrAllKeywords) - \count($arrExcludedMatches)) . ")";
			}
			else
			{
				$strQuery .= "+ ((1+LOG(SUM(match$index * tl_search_index.relevance))) * POW(MIN(match$index * matchedTerm.idf), 2) / " . (\count($arrAllKeywords) - \count($arrExcludedMatches)) . ")";
			}
		}

		$strQuery .= ") / sqrt(0";

		foreach ($arrAllKeywords as $index => $strKeywordExpression)
		{
			if (\in_array($index, $arrExcludedMatches, true))
			{
				continue;
			}

			if (isset($arrWildcards[$index]))
			{
				$strQuery .= " + POW(LOG((@searchCount + 1) / @wildcardCount$index) / " . (\count($arrAllKeywords) - \count($arrExcludedMatches)) . ", 2)";
			}
			else
			{
				$strQuery .= "+ POW(MIN(match$index * matchedTerm.idf) / " . (\count($arrAllKeywords) - \count($arrExcludedMatches)) . ", 2)";
			}
		}

		$strQuery .= ") AS similarity";
		$strQuery .= " FROM (SELECT id, term";

		// Calculate inverse document frequency of every matching term
		$strQuery .= ", LOG((@searchCount + 1) / GREATEST(1, documentFrequency)) AS idf";

		// Store the match of every keyword and wildcard in its own column match0, match1, ...
		foreach ($arrAllKeywords as $index => $strKeywordExpression)
		{
			$strQuery .= ", IF($strKeywordExpression, 1, null) AS match$index";
		}

		$strQuery .= " FROM ( SELECT ";
		$strQuery .= "@searchCount := (SELECT COUNT(*) FROM tl_search)";

		foreach ($arrWildcards as $index => $strKeyword)
		{
			$strQuery .= ", @wildcardCount$index := (
				SELECT COUNT(*) FROM (
					SELECT DISTINCT pid FROM tl_search_term
					JOIN tl_search_index ON tl_search_index.termId = tl_search_term.id
					WHERE term LIKE ?
				) distinctPids$index
			)";

			$arrValues[] = $strKeyword;
		}

		$strQuery .= ") variables, tl_search_term HAVING";

		// Select all terms in the sub query that match any of the keywords or wildcards
		$strQuery .= " match" . implode(" = 1 OR match", array_keys($arrAllKeywords)) . " = 1";
		$strQuery .= ") matchedTerm JOIN tl_search_index ON tl_search_index.termId = matchedTerm.id";
		$strQuery .= " GROUP BY tl_search_index.pid";

		$arrHaving = array();

		// Check that all required keywords match
		foreach ($blnOrSearch ? $arrRequiredMatches : array_merge($arrMatches, $arrRequiredMatches) as $intMatch)
		{
			$arrHaving[] = "COUNT(matchedTerm.match$intMatch) > 0";
		}

		// Check that none of the excluded keywords match
		foreach ($arrExcludedMatches as $intMatch)
		{
			$arrHaving[] = "COUNT(matchedTerm.match$intMatch) = 0";
		}

		if (\count($arrHaving))
		{
			$strQuery .= " HAVING " . implode(" AND ", $arrHaving);
		}

		$strQuery .= ") matches LEFT JOIN tl_search ON(matches.sid=tl_search.id) WHERE 1";

		// Get phrases
		if (\count($arrPhrases))
		{
			$strQuery .= " AND (" . implode(($blnOrSearch ? ' OR ' : ' AND '), array_fill(0, \count($arrPhrases), 'tl_search.text REGEXP ?')) . ')';
			$arrValues = array_merge($arrValues, $arrPhrases);
		}

		// Limit results to a particular set of pages
		if (!empty($arrPid) && \is_array($arrPid))
		{
			$strQuery .= " AND tl_search.pid IN(" . implode(',', array_map('\intval', $arrPid)) . ")";
		}

		// Sort by relevance
		$strQuery .= " ORDER BY relevance DESC";

		// Return result
		$objResultStmt = Database::getInstance()->prepare($strQuery);

		if ($intRows > 0)
		{
			$objResultStmt->limit($intRows, $intOffset);
		}

		$objResult = $objResultStmt->execute($arrValues);
		$arrResult = $objResult->fetchAllAssoc();

		foreach ($arrResult as $k=>$v)
		{
			if ((float) $v['relevance'] === 0.0)
			{
				$arrResult[$k]['relevance'] = PHP_FLOAT_EPSILON;
			}

			$arrHighlight = array();
			$arrMatches = explode(',', $v['matches']);

			foreach ($arrKeywords as $strKeyword)
			{
				if (\in_array($strKeyword, $arrMatches))
				{
					$arrHighlight[] = $strKeyword;
				}
			}

			foreach ($arrIncluded as $strKeyword)
			{
				if (\in_array($strKeyword, $arrMatches))
				{
					$arrHighlight[] = $strKeyword;
				}
			}

			// Highlight the words which matched the wildcard keywords
			foreach ($arrWildcards as $strKeyword)
			{
				if ($matches = preg_grep('/' . str_replace('%', '.*', $strKeyword) . '/', $arrMatches))
				{
					$arrHighlight = array_merge($arrHighlight, $matches);
				}
			}

			// Highlight phrases if all their words have matched
			foreach ($arrPhrases as $strPhrase)
			{
				$strPhrase = str_replace('[^[:alnum:]]+', ' ', $strPhrase);

				if (!array_diff(explode(' ', $strPhrase), $arrMatches))
				{
					$arrHighlight[] = $strPhrase;
				}
			}

			$arrResult[$k]['matches'] = implode(',', $arrHighlight);
		}

		return new Result($arrResult, $objResult->query);
	}

	/**
	 * Remove an entry from the search index
	 *
	 * @param string $strUrl The URL to be removed
	 */
	public static function removeEntry($strUrl)
	{
		$objDatabase = Database::getInstance();

		$objResult = $objDatabase->prepare("SELECT id FROM tl_search WHERE url=?")
								 ->execute($strUrl);

		while ($objResult->next())
		{
			// Decrement document frequency counts
			$objDatabase
				->prepare("
					UPDATE tl_search_term
					INNER JOIN tl_search_index ON tl_search_term.id = tl_search_index.termId AND tl_search_index.pid = ?
					SET documentFrequency = GREATEST(1, documentFrequency) - 1
				")
				->execute($objResult->id);

			$objDatabase->prepare("DELETE FROM tl_search WHERE id=?")
						->execute($objResult->id);

			$objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
						->execute($objResult->id);
		}

		// Remove obsolete terms
		$objDatabase->query("DELETE FROM tl_search_term WHERE documentFrequency = 0");
	}

	/**
	 * Prevent cloning of the object (Singleton)
	 *
	 * @deprecated Deprecated since Contao 4.0, to be removed in Contao 5.0.
	 *             The Search class is now static.
	 */
	final public function __clone()
	{
	}

	/**
	 * Return the object instance (Singleton)
	 *
	 * @return Search The object instance
	 *
	 * @deprecated Deprecated since Contao 4.0, to be removed in Contao 5.0.
	 *             The Search class is now static.
	 */
	public static function getInstance()
	{
		trigger_deprecation('contao/core-bundle', '4.0', 'Using "Contao\Search::getInstance()" has been deprecated and will no longer work in Contao 5.0. The "Contao\Search" class is now static.');

		if (static::$objInstance === null)
		{
			static::$objInstance = new static();
		}

		return static::$objInstance;
	}

	/**
	 * @param string $strUrlA
	 * @param string $strUrlB
	 *
	 * @return int negative if $strUrlA is more canonical, positive if $strUrlB is more canonical
	 */
	private static function compareUrls($strUrlA, $strUrlB)
	{
		if (strpos($strUrlA, '?') === false && strpos($strUrlB, '?') !== false)
		{
			return -1;
		}

		if (strpos($strUrlA, '?') !== false && strpos($strUrlB, '?') === false)
		{
			return 1;
		}

		$slashCountA = substr_count(explode('?', $strUrlA)[0], '/');
		$slashCountB = substr_count(explode('?', $strUrlB)[0], '/');

		if ($slashCountA !== $slashCountB)
		{
			return $slashCountA - $slashCountB;
		}

		if (\strlen($strUrlA) !== \strlen($strUrlB))
		{
			return \strlen($strUrlA) - \strlen($strUrlB);
		}

		return strcmp($strUrlA, $strUrlB);
	}
}

class_alias(Search::class, 'Search');
