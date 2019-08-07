<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;

/**
 * Provide methods to handle check boxes.
 *
 * @property array   $options
 * @property boolean $multiple
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class CheckBox extends Widget
{

	/**
	 * Submit user input
	 * @var boolean
	 */
	protected $blnSubmitInput = true;

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'be_widget_chk';

	/**
	 * Add specific attributes
	 *
	 * @param string $strKey
	 * @param mixed  $varValue
	 */
	public function __set($strKey, $varValue)
	{
		if ($strKey == 'options')
		{
			$this->arrOptions = StringUtil::deserialize($varValue);
		}
		else
		{
			parent::__set($strKey, $varValue);
		}
	}

	/**
	 * Check for a valid option (see #4383)
	 */
	public function validate()
	{
		$varValue = $this->getPost($this->strName);

		if (!empty($varValue) && !$this->isValidOption($varValue))
		{
			$this->addError($GLOBALS['TL_LANG']['ERR']['invalid']);
		}

		parent::validate();
	}

	/**
	 * Generate the widget and return it as string
	 *
	 * @return string
	 */
	public function generate()
	{
		$arrOptions = array();

		if (!$this->multiple && \count($this->arrOptions) > 1)
		{
			$this->arrOptions = array($this->arrOptions[0]);
		}

		// The "required" attribute only makes sense for single checkboxes
		if ($this->mandatory && !$this->multiple)
		{
			$this->arrAttributes['required'] = 'required';
		}

		/** @var AttributeBagInterface $objSessionBag */
		$objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

		$state = $objSessionBag->get('checkbox_groups');

		// Toggle the checkbox group
		if (Input::get('cbc'))
		{
			$state[Input::get('cbc')] = (isset($state[Input::get('cbc')]) && $state[Input::get('cbc')] == 1) ? 0 : 1;
			$objSessionBag->set('checkbox_groups', $state);
			$this->redirect(preg_replace('/(&(amp;)?|\?)cbc=[^& ]*/i', '', Environment::get('request')));
		}

		$blnFirst = true;
		$blnCheckAll = true;

		foreach ($this->arrOptions as $i=>$arrOption)
		{
			// Single dimension array
			if (is_numeric($i))
			{
				$arrOptions[] = $this->generateCheckbox($arrOption, $i);
				continue;
			}

			$id = 'cbc_' . $this->strId . '_' . StringUtil::standardize($i);

			$img = 'folPlus.svg';
			$display = 'none';

			if (!isset($state[$id]) || !empty($state[$id]))
			{
				$img = 'folMinus.svg';
				$display = 'block';
			}

			$arrOptions[] = '<div class="checkbox_toggler' . ($blnFirst ? '_first' : '') . '"><a href="' . Backend::addToUrl('cbc=' . $id) . '" onclick="AjaxRequest.toggleCheckboxGroup(this,\'' . $id . '\');Backend.getScrollOffset();return false">' . Image::getHtml($img) . '</a>' . $i . '</div><fieldset id="' . $id . '" class="tl_checkbox_container checkbox_options" style="display:' . $display . '"><input type="checkbox" id="check_all_' . $id . '" class="tl_checkbox" onclick="Backend.toggleCheckboxGroup(this, \'' . $id . '\')"> <label for="check_all_' . $id . '" style="color:#a6a6a6"><em>' . $GLOBALS['TL_LANG']['MSC']['selectAll'] . '</em></label>';

			// Multidimensional array
			foreach ($arrOption as $k=>$v)
			{
				$arrOptions[] = $this->generateCheckbox($v, StringUtil::standardize($i).'_'.$k);
			}

			$arrOptions[] = '</fieldset>';
			$blnFirst = false;
			$blnCheckAll = false;
		}

		// Add a "no entries found" message if there are no options
		if (empty($arrOptions))
		{
			$arrOptions[]= '<p class="tl_noopt">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>';
			$blnCheckAll = false;
		}

		if ($this->multiple)
		{
			return sprintf('<fieldset id="ctrl_%s" class="tl_checkbox_container%s"><legend>%s%s%s%s</legend><input type="hidden" name="%s" value="">%s%s</fieldset>%s',
							$this->strId,
							($this->strClass ? ' ' . $this->strClass : ''),
							($this->mandatory ? '<span class="invisible">'.$GLOBALS['TL_LANG']['MSC']['mandatory'].' </span>' : ''),
							$this->strLabel,
							($this->mandatory ? '<span class="mandatory">*</span>' : ''),
							$this->xlabel,
							$this->strName,
							($blnCheckAll ? '<input type="checkbox" id="check_all_' . $this->strId . '" class="tl_checkbox" onclick="Backend.toggleCheckboxGroup(this,\'ctrl_' . $this->strId . '\')' . ($this->onclick ? ';' . $this->onclick : '') . '"> <label for="check_all_' . $this->strId . '" style="color:#a6a6a6"><em>' . $GLOBALS['TL_LANG']['MSC']['selectAll'] . '</em></label><br>' : ''),
							str_replace('<br></fieldset><br>', '</fieldset>', implode('<br>', $arrOptions)),
							$this->wizard);
		}

		return sprintf('<div id="ctrl_%s" class="tl_checkbox_single_container%s"><input type="hidden" name="%s" value="">%s</div>%s',
						$this->strId,
						($this->strClass ? ' ' . $this->strClass : ''),
						$this->strName,
						str_replace('<br></div><br>', '</div>', implode('<br>', $arrOptions)),
						$this->wizard);
	}

	/**
	 * Generate a checkbox and return it as string
	 *
	 * @param array   $arrOption
	 * @param integer $i
	 *
	 * @return string
	 */
	protected function generateCheckbox($arrOption, $i)
	{
		return sprintf('<input type="checkbox" name="%s" id="opt_%s" class="tl_checkbox" value="%s"%s%s onfocus="Backend.getScrollOffset()"> <label for="opt_%s">%s%s%s</label>',
						$this->strName . ($this->multiple ? '[]' : ''),
						$this->strId.'_'.$i,
						($this->multiple ? StringUtil::specialchars($arrOption['value']) : 1),
						$this->isChecked($arrOption),
						$this->getAttributes(),
						$this->strId.'_'.$i,
						($this->mandatory && !$this->multiple ? '<span class="invisible">'.$GLOBALS['TL_LANG']['MSC']['mandatory'].' </span>' : ''),
						$arrOption['label'],
						($this->mandatory && !$this->multiple ? '<span class="mandatory">*</span>' : ''));
	}
}

class_alias(CheckBox::class, 'CheckBox');
