<?php

use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * A ViewableData object is any object that can be rendered into a template/view.
 *
 * A view interrogates the object being currently rendered in order to get data to render into the template. This data
 * is provided and automatically escaped by ViewableData. Any class that needs to be available to a view (controllers,
 * {@link DataObject}s, page controls) should inherit from this class.
 *
 * @package framework
 * @subpackage view
 */
class ViewableData extends Object implements IteratorAggregate {

	/**
	 * An array of objects to cast certain fields to. This is set up as an array in the format:
	 *
	 * <code>
	 * public static $casting = array (
	 *     'FieldName' => 'ClassToCastTo(Arguments)'
	 * );
	 * </code>
	 *
	 * @var array
	 * @config
	 */
	private static $casting = array(
		'CSSClasses' => 'Varchar'
	);

	/**
	 * The default object to cast scalar fields to if casting information is not specified, and casting to an object
	 * is required.
	 *
	 * @var string
	 * @config
	 */
	private static $default_cast = 'Text';

	/**
	 * @var array
	 */
	private static $casting_cache = array();

	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * A failover object to attempt to get data from if it is not present on this object.
	 *
	 * @var ViewableData
	 */
	protected $failover;

	/**
	 * @var ViewableData
	 */
	protected $customisedObject;

	/**
	 * @var array
	 */
	private $objCache = array();

	// -----------------------------------------------------------------------------------------------------------------

	// FIELD GETTERS & SETTERS -----------------------------------------------------------------------------------------

	/**
	 * Check if a field exists on this object or its failover.
	 *
	 * @param string $property
	 * @return bool
	 */
	public function __isset($property) {
		return $this->hasField($property) || ($this->failover && $this->failover->hasField($property));
	}

	/**
	 * Get the value of a property/field on this object. This will check if a method called get{$property} exists, then
	 * check if a field is available using {@link ViewableData::getField()}, then fall back on a failover object.
	 *
	 * @param string $property
	 * @return mixed
	 */
	public function __get($property) {
		if($this->hasMethod($method = "get$property")) {
			return $this->$method();
		} elseif($this->hasField($property)) {
			return $this->getField($property);
		} elseif($this->failover) {
			return $this->failover->$property;
		}
		return null;
	}

	/**
	 * Set a property/field on this object. This will check for the existence of a method called set{$property}, then
	 * use the {@link ViewableData::setField()} method.
	 *
	 * @param string $property
	 * @param mixed $value
	 */
	public function __set($property, $value) {
		$this->objCacheClear();
		if($this->hasMethod($method = "set$property")) {
			$this->$method($value);
		} else {
			$this->setField($property, $value);
		}
	}

	/**
	 * Set a failover object to attempt to get data from if it is not present on this object.
	 *
	 * @param ViewableData $failover
	 */
	public function setFailover(ViewableData $failover) {
		// Ensure cached methods from previous failover are removed
		if ($this->failover) {
			$this->removeMethodsFrom('failover');
		}

		$this->failover = $failover;
		$this->defineMethods();
	}

	/**
	 * Get the current failover object if set
	 *
	 * @return ViewableData|null
	 */
	public function getFailover() {
		return $this->failover;
	}

	/**
	 * Check if a field exists on this object. This should be overloaded in child classes.
	 *
	 * @param string $field
	 * @return bool
	 */
	public function hasField($field) {
		return property_exists($this, $field);
	}

	/**
	 * Get the value of a field on this object. This should be overloaded in child classes.
	 *
	 * @param string $field
	 * @return mixed
	 */
	public function getField($field) {
		return $this->$field;
	}

	/**
	 * Set a field on this object. This should be overloaded in child classes.
	 *
	 * @param string $field
	 * @param mixed $value
	 * @return $this
	 */
	public function setField($field, $value) {
		$this->objCacheClear();
		$this->$field = $value;
		return $this;
	}

	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * Add methods from the {@link ViewableData::$failover} object, as well as wrapping any methods prefixed with an
	 * underscore into a {@link ViewableData::cachedCall()}.
	 *
	 * @throws LogicException
	 */
	public function defineMethods() {
		if($this->failover && !is_object($this->failover)) {
			throw new LogicException("ViewableData::\$failover set to a non-object");
		}
		if($this->failover) {
			$this->addMethodsFrom('failover');

			if(isset($_REQUEST['debugfailover'])) {
				Debug::message("$this->class created with a failover class of {$this->failover->class}");
			}
		}

		parent::defineMethods();
	}

	/**
	 * Merge some arbitrary data in with this object. This method returns a {@link ViewableData_Customised} instance
	 * with references to both this and the new custom data.
	 *
	 * Note that any fields you specify will take precedence over the fields on this object.
	 *
	 * @param array|ViewableData $data
	 * @return ViewableData_Customised
	 */
	public function customise($data) {
		if(is_array($data) && (empty($data) || ArrayLib::is_associative($data))) {
			$data = new ArrayData($data);
		}

		if($data instanceof ViewableData) {
			return new ViewableData_Customised($this, $data);
		}

		throw new InvalidArgumentException (
			'ViewableData->customise(): $data must be an associative array or a ViewableData instance'
		);
	}

	/**
	 * @return ViewableData
	 */
	public function getCustomisedObj() {
		return $this->customisedObject;
	}

	/**
	 * @param ViewableData $object
	 */
	public function setCustomisedObj(ViewableData $object) {
		$this->customisedObject = $object;
	}

	// CASTING ---------------------------------------------------------------------------------------------------------

	/**
	 * Return the "casting helper" (a piece of PHP code that when evaluated creates a casted value object)
	 * for a field on this object. This helper will be a subclass of DBField.
	 *
	 * @param string $field
	 * @return string Casting helper As a constructor pattern, and may include arguments.
	 */
	public function castingHelper($field) {
		$specs = $this->config()->casting;
		if(isset($specs[$field])) {
			return $specs[$field];
		}

		// If no specific cast is declared, fall back to failover.
		// Note that if there is a failover, the default_cast will always
		// be drawn from this object instead of the top level object.
		$failover = $this->getFailover();
		if($failover) {
			$cast = $failover->castingHelper($field);
			if($cast) {
				return $cast;
		}
	}

		// Fall back to default_cast
		return $this->config()->get('default_cast');
	}

	/**
	 * Get the class name a field on this object will be casted to.
	 *
	 * @param string $field
	 * @return string
	 */
	public function castingClass($field) {
		// Strip arguments
		$spec = $this->castingHelper($field);
		return trim(strtok($spec, '('));
	}

	/**
	 * Return the string-format type for the given field.
	 *
	 * @param string $field
	 * @return string 'xml'|'raw'
	 */
	public function escapeTypeForField($field) {
		$class = $this->castingClass($field) ?: $this->config()->default_cast;

		// TODO: It would be quicker not to instantiate the object, but to merely
		// get its class from the Injector
		return Injector::inst()->get($class, true)->config()->escape_type;
	}

	// TEMPLATE ACCESS LAYER -------------------------------------------------------------------------------------------

	/**
	 * Render this object into the template, and get the result as a string. You can pass one of the following as the
	 * $template parameter:
	 *  - a template name (e.g. Page)
	 *  - an array of possible template names - the first valid one will be used
	 *  - an SSViewer instance
	 *
	 * @param string|array|SSViewer $template the template to render into
	 * @param array $customFields fields to customise() the object with before rendering
	 * @return DBHTMLText
	 */
	public function renderWith($template, $customFields = null) {
		if(!is_object($template)) {
			$template = new SSViewer($template);
		}

		$data = ($this->customisedObject) ? $this->customisedObject : $this;

		if($customFields instanceof ViewableData) {
			$data = $data->customise($customFields);
		}
		if($template instanceof SSViewer) {
			return $template->process($data, is_array($customFields) ? $customFields : null);
		}

		throw new UnexpectedValueException (
			"ViewableData::renderWith(): unexpected $template->class object, expected an SSViewer instance"
		);
	}

	/**
	 * Generate the cache name for a field
	 *
	 * @param string $fieldName Name of field
	 * @param array $arguments List of optional arguments given
	 * @return string
	 */
	protected function objCacheName($fieldName, $arguments) {
		return $arguments
			? $fieldName . ":" . implode(',', $arguments)
			: $fieldName;
	}

	/**
	 * Get a cached value from the field cache
	 *
	 * @param string $key Cache key
	 * @return mixed
	 */
	protected function objCacheGet($key) {
		if(isset($this->objCache[$key])) {
			return $this->objCache[$key];
		}
		return null;
	}

	/**
	 * Store a value in the field cache
	 *
	 * @param string $key Cache key
	 * @param mixed $value
	 * @return $this
	 */
	protected function objCacheSet($key, $value) {
		$this->objCache[$key] = $value;
		return $this;
	}

	/**
	 * Clear object cache
	 *
	 * @return $this
	 */
	protected function objCacheClear() {
		$this->objCache = [];
		return $this;
	}

	/**
	 * Get the value of a field on this object, automatically inserting the value into any available casting objects
	 * that have been specified.
	 *
	 * @param string $fieldName
	 * @param array $arguments
	 * @param bool $cache Cache this object
	 * @param string $cacheName a custom cache name
	 * @return Object|DBField
	 */
	public function obj($fieldName, $arguments = [], $cache = false, $cacheName = null) {
		if(!$cacheName && $cache) {
			$cacheName = $this->objCacheName($fieldName, $arguments);
		}

		// Check pre-cached value
		$value = $cache ? $this->objCacheGet($cacheName) : null;
		if($value !== null) {
			return $value;
		}

		// Load value from record
		if($this->hasMethod($fieldName)) {
			$value = call_user_func_array(array($this, $fieldName), $arguments ?: []);
		} else {
			$value = $this->$fieldName;
		}

		// Cast object
		if(!is_object($value)) {
			// Force cast
			$castingHelper = $this->castingHelper($fieldName);
			$valueObject = Object::create_from_string($castingHelper, $fieldName);
			$valueObject->setValue($value, $this);
			$value = $valueObject;
		}

		// Record in cache
		if($cache) {
			$this->objCacheSet($cacheName, $value);
		}

		return $value;
	}

	/**
	 * A simple wrapper around {@link ViewableData::obj()} that automatically caches the result so it can be used again
	 * without re-running the method.
	 *
	 * @param string $field
	 * @param array $arguments
	 * @param string $identifier an optional custom cache identifier
	 * @return Object|DBField
	 */
	public function cachedCall($field, $arguments = [], $identifier = null) {
		return $this->obj($field, $arguments, true, $identifier);
	}

	/**
	 * Checks if a given method/field has a valid value. If the result is an object, this will return the result of the
	 * exists method, otherwise will check if the result is not just an empty paragraph tag.
	 *
	 * @param string $field
	 * @param array $arguments
	 * @param bool $cache
	 * @return bool
	 */
	public function hasValue($field, $arguments = [], $cache = true) {
		$result = $this->obj($field, $arguments, $cache);
			return $result->exists();
		}

	/**
	 * Get the string value of a field on this object that has been suitable escaped to be inserted directly into a
	 * template.
	 *
	 * @param string $field
	 * @param array $arguments
	 * @param bool $cache
	 * @return string
	 */
	public function XML_val($field, $arguments = [], $cache = false) {
		$result = $this->obj($field, $arguments, $cache);
		// Might contain additional formatting over ->XML(). E.g. parse shortcodes, nl2br()
		return $result->forTemplate();
	}

	/**
	 * Get an array of XML-escaped values by field name
	 *
	 * @param array $fields an array of field names
	 * @return array
	 */
	public function getXMLValues($fields) {
		$result = array();

		foreach($fields as $field) {
			$result[$field] = $this->XML_val($field);
		}

		return $result;
	}

	// ITERATOR SUPPORT ------------------------------------------------------------------------------------------------

	/**
	 * Return a single-item iterator so you can iterate over the fields of a single record.
	 *
	 * This is useful so you can use a single record inside a <% control %> block in a template - and then use
	 * to access individual fields on this object.
	 *
	 * @return ArrayIterator
	 */
	public function getIterator() {
		return new ArrayIterator(array($this));
	}

	// UTILITY METHODS -------------------------------------------------------------------------------------------------

	/**
	 * When rendering some objects it is necessary to iterate over the object being rendered, to do this, you need
	 * access to itself.
	 *
	 * @return ViewableData
	 */
	public function Me() {
		return $this;
	}

	/**
	 * Return the directory if the current active theme (relative to the site root).
	 *
	 * This method is useful for things such as accessing theme images from your template without hardcoding the theme
	 * page - e.g. <img src="$ThemeDir/images/something.gif">.
	 *
	 * This method should only be used when a theme is currently active. However, it will fall over to the current
	 * project directory.
	 *
	 * @param string $subtheme the subtheme path to get
	 * @return string
	 */
	public function ThemeDir($subtheme = null) {
		if(
			Config::inst()->get('SSViewer', 'theme_enabled')
			&& $theme = Config::inst()->get('SSViewer', 'theme')
		) {
			return THEMES_DIR . "/$theme" . ($subtheme ? "_$subtheme" : null);
		}

		return project();
	}

	/**
	 * Get part of the current classes ancestry to be used as a CSS class.
	 *
	 * This method returns an escaped string of CSS classes representing the current classes ancestry until it hits a
	 * stop point - e.g. "Page DataObject ViewableData".
	 *
	 * @param string $stopAtClass the class to stop at (default: ViewableData)
	 * @return string
	 * @uses ClassInfo
	 */
	public function CSSClasses($stopAtClass = 'ViewableData') {
		$classes       = array();
		$classAncestry = array_reverse(ClassInfo::ancestry($this->class));
		$stopClasses   = ClassInfo::ancestry($stopAtClass);

		foreach($classAncestry as $class) {
			if(in_array($class, $stopClasses)) break;
			$classes[] = $class;
		}

		// optionally add template identifier
		if(isset($this->template) && !in_array($this->template, $classes)) {
			$classes[] = $this->template;
		}

		// Strip out namespaces
		$classes = preg_replace('#.*\\\\#', '', $classes);

		return Convert::raw2att(implode(' ', $classes));
	}

	/**
	 * Return debug information about this object that can be rendered into a template
	 *
	 * @return ViewableData_Debugger
	 */
	public function Debug() {
		return new ViewableData_Debugger($this);
	}

}

/**
 * @package framework
 * @subpackage view
 */
class ViewableData_Customised extends ViewableData {

	/**
	 * @var ViewableData
	 */
	protected $original, $customised;

	/**
	 * Instantiate a new customised ViewableData object
	 *
	 * @param ViewableData $originalObject
	 * @param ViewableData $customisedObject
	 */
	public function __construct(ViewableData $originalObject, ViewableData $customisedObject) {
		$this->original   = $originalObject;
		$this->customised = $customisedObject;

		$this->original->setCustomisedObj($this);

		parent::__construct();
	}

	public function __call($method, $arguments) {
		if($this->customised->hasMethod($method)) {
			return call_user_func_array(array($this->customised, $method), $arguments);
		}

		return call_user_func_array(array($this->original, $method), $arguments);
	}

	public function __get($property) {
		if(isset($this->customised->$property)) {
			return $this->customised->$property;
		}

		return $this->original->$property;
	}

	public function __set($property, $value) {
		$this->customised->$property = $this->original->$property = $value;
	}

	public function hasMethod($method) {
		return $this->customised->hasMethod($method) || $this->original->hasMethod($method);
	}

	public function cachedCall($field, $arguments = null, $identifier = null) {
		if($this->customised->hasMethod($field) || $this->customised->hasField($field)) {
			return $this->customised->cachedCall($field, $arguments, $identifier);
		}
		return $this->original->cachedCall($field, $arguments, $identifier);
	}

	public function obj($fieldName, $arguments = null, $cache = false, $cacheName = null) {
		if($this->customised->hasField($fieldName) || $this->customised->hasMethod($fieldName)) {
			return $this->customised->obj($fieldName, $arguments, $cache, $cacheName);
		}
		return $this->original->obj($fieldName, $arguments,  $cache, $cacheName);
	}

}

/**
 * Allows you to render debug information about a {@link ViewableData} object into a template.
 *
 * @package framework
 * @subpackage view
 */
class ViewableData_Debugger extends ViewableData {

	/**
	 * @var ViewableData
	 */
	protected $object;

	/**
	 * @param ViewableData $object
	 */
	public function __construct(ViewableData $object) {
		$this->object = $object;
		parent::__construct();
	}

	/**
	 * @return string The rendered debugger
	 */
	public function __toString() {
		return (string)$this->forTemplate();
	}

	/**
	 * Return debugging information, as XHTML. If a field name is passed, it will show debugging information on that
	 * field, otherwise it will show information on all methods and fields.
	 *
	 * @param string $field the field name
	 * @return string
	 */
	public function forTemplate($field = null) {
		// debugging info for a specific field
		if($field) return "<b>Debugging Information for {$this->class}->{$field}</b><br/>" .
			($this->object->hasMethod($field)? "Has method '$field'<br/>" : null)             .
			($this->object->hasField($field) ? "Has field '$field'<br/>"  : null)             ;

		// debugging information for the entire class
		$reflector = new ReflectionObject($this->object);
		$debug     = "<b>Debugging Information: all methods available in '{$this->object->class}'</b><br/><ul>";

		foreach($this->object->allMethodNames() as $method) {
			// check that the method is public
			if($method[0] === strtoupper($method[0]) && $method[0] != '_') {
				if($reflector->hasMethod($method) && $method = $reflector->getMethod($method)) {
					if($method->isPublic()) {
						$debug .= "<li>\${$method->getName()}";

						if(count($method->getParameters())) {
							$debug .= ' <small>(' . implode(', ', $method->getParameters()) . ')</small>';
						}

						$debug .= '</li>';
					}
				} else {
					$debug .= "<li>\$$method</li>";
				}
			}
		}

		$debug .= '</ul>';

		if($this->object->hasMethod('toMap')) {
			$debug .= "<b>Debugging Information: all fields available in '{$this->object->class}'</b><br/><ul>";

			foreach($this->object->toMap() as $field => $value) {
				$debug .= "<li>\$$field</li>";
			}

			$debug .= "</ul>";
		}

		// check for an extra attached data
		if($this->object->hasMethod('data') && $this->object->data() != $this->object) {
			$debug .= ViewableData_Debugger::create($this->object->data())->forTemplate();
		}

		return $debug;
	}

}
