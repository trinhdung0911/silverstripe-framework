<?php

/**
 * Readonly field equivalent for literal HTML
 *
 * Unlike HTMLEditorField_Readonly, does not processs shortcodes
 */
class HTMLReadonlyField extends ReadonlyField {
	private static $casting = [
		'Value' => 'HTMLFragment',
		'ValueEntities' => 'HTMLFragment',
	];

	public function Field($properties = array()) {
		return $this->renderWith($this->getTemplates());
	}

	/**
	 * Return value with all values encoded in html entities
	 *
	 * @return string Raw HTML
	 */
	public function ValueEntities() {
		return htmlentities($this->Value(), ENT_COMPAT, 'UTF-8');
	}
}
