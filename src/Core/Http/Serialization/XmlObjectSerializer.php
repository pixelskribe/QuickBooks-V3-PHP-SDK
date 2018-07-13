<?php

namespace QuickBooksOnline\API\Core\Http\Serialization;

use QuickbooksOnline\API\Core\CoreConstants;
use QuickBooksOnline\API\XSD2PHP\src\com\mikebevz\xsd2php\Php2Xml;
use QuickBooksOnline\API\XSD2PHP\src\com\mikebevz\xsd2php\Bind;

/**
 * Xml Serialize(r) to serialize and de serialize.
 */
class XmlObjectSerializer extends IEntitySerializer
{

	/**
	 * IDS Logger
	 * @var ILogger
	 */
	public $IDSLogger;

	/**
	 * Keeps last used object name
	 * @var String
	 */
	private $resourceURL = null;


	/**
	 * Marshall a POPO object to XML, presumably for inclusion on an IPP v3 API call
	 *
	 * @param POPOObject $phpObj inbound POPO object
	 * @return string XML output derived from POPO object
	 */
	private static function getXmlFromObj($phpObj)
	{
		if (!$phpObj) {
			echo "getXmlFromObj NULL arg\n";
			var_dump(debug_backtrace());
			return false;
		}

		$php2xml = new Php2Xml(CoreConstants::PHP_CLASS_PREFIX);
		$php2xml->overrideAsSingleNamespace = 'http://schema.intuit.com/finance/v3';

		try {
			return $php2xml->getXml($phpObj);
		} catch (Exception $e) {
			echo "\n" . "Object Dump:\n";
			var_dump($phpObj);
			echo "\n" . "Exception Call Stack (" . $e->getMessage() . "):\n";
			echo "\n" . "In  (" . $e->getFile() . ") on " . $e->getLine();
			array_walk(debug_backtrace(), create_function('$a,$b', 'print "\t{$a[\'function\']}()\n\t".basename($a[\'file\']).":{$a[\'line\']}\n";'));
			return false;
		}
	}

	/**
	 * Marshall a POPO object to be XML
	 *
	 * @param IPPIntuitEntity $entity The POPO object
	 * @param string $urlResource the type of the POPO object
	 * @return string the XML of the POPO object
	 */
	public static function getPostXmlFromArbitraryEntity($entity, &$urlResource)
	{
		if (null == $entity) {
			return false;
		}

		$xmlElementName = XmlObjectSerializer::cleanPhpClassNameToIntuitEntityName(get_class($entity));
		$xmlElementName = trim($xmlElementName);
		$urlResource = strtolower($xmlElementName);
		$httpsPostBody = XmlObjectSerializer::getXmlFromObj($entity);
		return $httpsPostBody;
	}

	/**
	 * Unmarshall XML into a POPO object, presumably the XML came from an IPP v3 API call
	 *
	 * @param string XML that conforms to IPP v3 XSDs
	 * @return POPOObject $phpObj resulting POPO object
	 */
	private static function PhpObjFromXml($className, $xmlStr)
	{
		$className = trim($className);

		if (class_exists($className)) {
			$phpObj = new $className;
		} elseif (class_exists(CoreConstants::NAMEPSACE_DATA_PREFIX . $className)) {
			$className = CoreConstants::NAMEPSACE_DATA_PREFIX . $className;
			$phpObj = new $className;
		} else {
			throw new \Exception("Can't find corresponding CLASS for className" . $className . "during unmarshall XML into POPO Object");
		}

		$xmlItemArray = XmlObjectSerializer::xml2array($xmlStr);
		foreach($xmlItemArray as $items){
			foreach ($items as $item => $value){
				//hack to recursively convert array
				$value =  json_decode(json_encode($value,JSON_FORCE_OBJECT),false);
				$phpObj->{$item} = $value;
			}
		}
		/* #### original code #### */
		//		$bind = new Bind(CoreConstants::PHP_CLASS_PREFIX);
		//  	$bind->overrideAsSingleNamespace = 'http://schema.intuit.com/finance/v3';
		//		$bind->bindXml($xmlStr, $phpObj);

		return $phpObj;
	}

	/**
	 * Parse an XML string into an array of IPPIntuitEntity objects
	 *
	 * @param string $responseXml XML string to parse
	 * @param bool $bLimitToOne Signals to only parse the first element
	 * @return array of IPPIntuitEntity objects
	 */
	private static function ParseArbitraryResultObjects($responseXml, $bLimitToOne)
	{
		if (!$responseXml) {
			return null;
		}

		$resultObject = null;
		$resultObjects = null;

		$responseXmlObj = simplexml_load_string($responseXml);
		foreach ($responseXmlObj as $oneXmlObj) {
			$oneXmlElementName = (string)$oneXmlObj->getName();

			//The handling falut here is a little too simple. add more support for future
			//@hao
			if ('Fault' == $oneXmlElementName) {
				return null;
			}
			$phpClassName = XmlObjectSerializer::decorateIntuitEntityToPhpClassName($oneXmlElementName);
			$onePhpObj = XmlObjectSerializer::PhpObjFromXml($phpClassName, $oneXmlObj->asXML());
			$resultObject = $onePhpObj;
			$resultObjects[] = $onePhpObj;

			// Caller may be anticipating ONLY one object in result
			if ($bLimitToOne) {
				break;
			}
		}

		if ($bLimitToOne) {
			return $resultObject;
		} else {
			return $resultObjects;
		}
	}

	/**
	 * Decorate an IPP v3 Entity name (like 'Class') to be a POPO class name (like 'IPPClass')
	 *
	 * @param string Intuit Entity name
	 * @return POPO class name
	 */
	private static function decorateIntuitEntityToPhpClassName($intuitEntityName)
	{
		$intuitEntityName = trim($intuitEntityName);
		return CoreConstants::PHP_CLASS_PREFIX . $intuitEntityName;
	}

	/**
	 * Clean a POPO class name (like 'IPPClass') to be an IPP v3 Entity name (like 'Class')
	 *
	 * @param string $phpClassName POPO class name
	 * @return string Intuit Entity name
	 */
	public static function cleanPhpClassNameToIntuitEntityName($phpClassName)
	{
		$phpClassName = trim($phpClassName);
		//if the className has delimiters, get the last part
		$separetes = explode('\\', $phpClassName);
		$phpClassName = end($separetes);
		if (0 == strpos($phpClassName, CoreConstants::PHP_CLASS_PREFIX)) {
			return substr($phpClassName, strlen(CoreConstants::PHP_CLASS_PREFIX));
		}

		return null;
	}


	/**
	 * Initializes a new instance of the XmlObjectSerializer class.
	 * @param ILogger idsLogger The ids logger.
	 */
	public function __construct($idsLogger = null)
	{
		if ($idsLogger) {
			$this->IDSLogger = $idsLogger;
		} else {
			$this->IDSLogger = null;
		} // new TraceLogger();
	}

	/**
	 * Serializes the specified entity and updates last used entity name @see resourceURL
	 * @param object entity The entity.
	 * @return string Returns the serialize entity in string format.
	 */
	public function Serialize($entity)
	{
		$this->resetResourceURL();
		return XmlObjectSerializer::getPostXmlFromArbitraryEntity($entity, $this->resourceURL);
	}

	/**
	 * Reset value for resourceURL to null
	 *
	 */
	public function resetResourceURL()
	{
		$this->resourceURL = null;
	}

	/**
	 * Returns last used resource URL (which entity name)
	 * @return string
	 */
	public function getResourceURL()
	{
		return $this->resourceURL;
	}


	/**
	 * DeSerializes the specified action entity type.
	 * @param message The type to be  serialize to
	 * @param bLimitToOne Limit to parsing just one response element
	 * @return object Returns the de serialized object.
	 */
	public function Deserialize($message, $bLimitToOne = false)
	{
		if (!$message) {
			return null;
		}

		$resultObject = null;
		$resultObjects = null;

		$responseXmlObj = simplexml_load_string($message);

		foreach ($responseXmlObj as $oneXmlObj) {
			$oneXmlElementName = (string)$oneXmlObj->getName();
			if ('Fault' == $oneXmlElementName) {
				return null;
			}

			$phpClassName = XmlObjectSerializer::decorateIntuitEntityToPhpClassName($oneXmlElementName);
			$onePhpObj = XmlObjectSerializer::PhpObjFromXml($phpClassName, $oneXmlObj->asXML());
			$resultObject = $onePhpObj;
			$resultObjects[] = $onePhpObj;

			// Caller may be anticipating ONLY one object in result
			if ($bLimitToOne) {
				break;
			}
		}
		if ($bLimitToOne) {
			return $resultObject;
		} else {
			return $resultObjects;
		}
	}

	/**
	 * Credit to http://www.bin-co.com/php/scripts/xml2array/
	 *
	 * xml2array() will convert the given XML text to an array in the XML structure.
	 * Link: http://www.bin-co.com/php/scripts/xml2array/
	 * Arguments : $contents - The XML text
	 *                $get_attributes - 1 or 0. If this is 1 the function will get the attributes as well as the tag values - this results in a different array structure in the return value.
	 *                $priority - Can be 'tag' or 'attribute'. This will change the way the resulting array sturcture. For 'tag', the tags are given more importance.
	 * Return: The parsed XML in an array form. Use print_r() to see the resulting array structure.
	 * Examples: $array =  xml2array(file_get_contents('feed.xml'));
	 *              $array =  xml2array(file_get_contents('feed.xml', 1, 'attribute'));
	 */
	public static function xml2array($contents, $get_attributes = 1, $priority = 'tag')
	{
		if (!$contents) return array();

		if (!function_exists('xml_parser_create')) {
			//print "'xml_parser_create()' function not found!";
			return array();
		}

		//Get the XML parser of PHP - PHP must have this module for the parser to work
		$parser = xml_parser_create('');
		xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, trim($contents), $xml_values);
		xml_parser_free($parser);

		if (!$xml_values) return;//Hmm...

		//Initializations
		$xml_array = array();
		$parents = array();
		$opened_tags = array();
		$arr = array();

		$current = &$xml_array; //Refference

		//Go through the tags.
		$repeated_tag_index = array();//Multiple tags with same name will be turned into an array
		foreach ($xml_values as $data) {
			unset($attributes, $value);//Remove existing values, or there will be trouble

			//This command will extract these variables into the foreach scope
			// tag(string), type(string), level(int), attributes(array).
			extract($data);//We could use the array by itself, but this cooler.

			$result = array();
			$attributes_data = array();

			if (isset($value)) {
				if ($priority == 'tag') $result = $value;
				else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode
			}

			//Set the attributes too.
			if (isset($attributes) and $get_attributes) {
				foreach ($attributes as $attr => $val) {
					if ($priority == 'tag') $attributes_data[$attr] = $val;
					else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
				}
			}

			//See tag status and do the needed.
			if ($type == "open") {//The starting of the tag '<tag>'
				$parent[$level - 1] = &$current;
				if (!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
					$current[$tag] = $result;
					if ($attributes_data) $current[$tag . '_attr'] = $attributes_data;
					$repeated_tag_index[$tag . '_' . $level] = 1;

					$current = &$current[$tag];

				} else { //There was another element with the same tag name

					if (isset($current[$tag][0])) {//If there is a 0th element it is already an array
						$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
						$repeated_tag_index[$tag . '_' . $level]++;
					} else {//This section will make the value an array if multiple tags with the same name appear together
						$current[$tag] = array($current[$tag], $result);//This will combine the existing item and the new item together to make an array
						$repeated_tag_index[$tag . '_' . $level] = 2;

						if (isset($current[$tag . '_attr'])) { //The attribute of the last(0th) tag must be moved as well
							$current[$tag]['0_attr'] = $current[$tag . '_attr'];
							unset($current[$tag . '_attr']);
						}

					}
					$last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
					$current = &$current[$tag][$last_item_index];
				}

			} elseif ($type == "complete") { //Tags that ends in 1 line '<tag />'
				//See if the key is already taken.
				if (!isset($current[$tag])) { //New Key
					$current[$tag] = $result;
					$repeated_tag_index[$tag . '_' . $level] = 1;
					if ($priority == 'tag' and $attributes_data) $current[$tag . '_attr'] = $attributes_data;

				} else { //If taken, put all things inside a list(array)
					if (isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array...

						// ...push the new element into that array.
						$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;

						if ($priority == 'tag' and $get_attributes and $attributes_data) {
							$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
						}
						$repeated_tag_index[$tag . '_' . $level]++;

					} else { //If it is not an array...
						$current[$tag] = array($current[$tag], $result); //...Make it an array using using the existing value and the new value
						$repeated_tag_index[$tag . '_' . $level] = 1;
						if ($priority == 'tag' and $get_attributes) {
							if (isset($current[$tag . '_attr'])) { //The attribute of the last(0th) tag must be moved as well

								$current[$tag]['0_attr'] = $current[$tag . '_attr'];
								unset($current[$tag . '_attr']);
							}

							if ($attributes_data) {
								$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
							}
						}
						$repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
					}
				}

			} elseif ($type == 'close') { //End of tag '</tag>'
				$current = &$parent[$level - 1];
			}
		}

		return $xml_array;
	}
}