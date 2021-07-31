<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use DateTime;
use DateTimeInterface;
use dev\winterframework\io\ObjectMapper;
use dev\winterframework\paxb\attr\XmlAnyAttribute;
use dev\winterframework\paxb\attr\XmlAnyElement;
use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlValue;
use dev\winterframework\paxb\ex\NodeBindDoesNotExist;
use dev\winterframework\paxb\ex\PaxbException;
use dev\winterframework\paxb\LibxmlUtil;
use dev\winterframework\paxb\XmlValueAdapter;
use dev\winterframework\reflection\ObjectPropertySetter;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\VariableResource;
use dev\winterframework\util\log\Wlf4p;
use Throwable;
use XMLReader;

class XmlReaderObjectCreator {
    use Wlf4p;
    use ObjectPropertySetter;
    use XmlResourceScanner;

    /**
     * @var XmlValueAdapter[]
     */
    protected array $valueAdapters = [];

    public function __construct(
        protected XMLReader $reader,
        protected string $class,
        /**
         * @var XmlBeanAnnotations[]
         */
        protected array $resources = []
    ) {
    }

    public function getResources(): array {
        return $this->resources;
    }

    public function create(bool $validate = false): object {
        if ($validate) {
            LibxmlUtil::init();
        }
        $cls = RefKlass::getInstance($this->class);

        if (empty($this->resources)) {
            $this->resources = $this->scanForPaxbClasses($cls);
        }
        $this->validate();

        $obj = $cls->newInstance();
        $curObj = $obj;
        $curCls = $this->resources[$cls->getName()];
        /** @var object[] $objectStore */
        $objectStore = [1 => $curObj];
        $classStore = [1 => $curCls];

        $depth = 0;
        while ($this->reader->read()) {

            if ($validate && LibxmlUtil::hasXmlError()) {
                throw new PaxbException(LibxmlUtil::getXmlError());
            }

            $nodeType = $this->reader->nodeType;

            while (1) {
                switch ($nodeType) {
                    case XMLReader::ELEMENT:
                        $isEmpty = $this->reader->isEmptyElement;
                        if ($isEmpty && !$this->reader->hasAttributes) {
                            break;
                        }

                        $depth++;
                        //echo "\n" . __LINE__ . ') Element: ' . $this->reader->name, ', depth:' . $depth;
                        //echo ", isEmpty: $isEmpty\n";

                        try {
                            if (!isset($objectStore[$depth])) {
                                list($curObj, $curCls)
                                    = $this->initObjectFromElement($objectStore[$depth - 1], $classStore[$depth - 1]);

                                //echo __LINE__ . ') ' . get_class($curObj) . "\n";
                                if (!is_null($curObj)) {
                                    $objectStore[$depth] = $curObj;
                                    $classStore[$depth] = $curCls;
                                }
                            }
                            if (!is_null($curObj)) {
                                $this->mapXmlAttributes($curObj, $curCls);
                            }
                        } catch (NodeBindDoesNotExist $e) {
                            self::logDebug($e->getMessage());
                            $depth--;
                            $this->reader->next();
                            continue 3;
                        }

                        if ($isEmpty) {
                            $nodeType = XMLReader::END_ELEMENT;
                            continue 2;
                        }

                        break;

                    case XMLReader::TEXT:
                    case XMLReader::CDATA:
                        //echo 'TEXT ' . $this->reader->value, ', depth:' . $depth . "\n";
                        if (!is_null($curObj)) {
                            $this->mapXmlValue($curObj, $curCls);
                        }
                        break;

                    case XMLReader::END_ELEMENT:
                        $depth--;
                        if (isset($objectStore[$depth])) {
                            $curObj = $objectStore[$depth];
                            $curCls = $classStore[$depth];
                            unset($objectStore[$depth + 1], $classStore[$depth + 1]);
                            //echo 'Unsetting ... ' . $this->reader->name, ', depth:' . ($depth) . "\n";
                        } else {
                            $curObj = null;
                            $curCls = null;
                            //echo 'END_ELEMENT curObj setting to null, ' . $this->reader->name, ', depth:' . $depth . "\n";
                        }
                        break;
                }

                break;
            }
        }

        return $obj;
    }

    protected function initObjectFromElement(object $parent, XmlBeanAnnotations $parentDef): array {
        $tagName = $this->reader->name;

        /** @var XmlBeanAnnotation[] $elements */
        $elements = $parentDef->getAttributesBy(XmlElement::class);
        /** @var XmlBeanAnnotation[] $anyElement */
        $anyElement = $parentDef->getAttributesBy(XmlAnyElement::class);

        $bindAnnotation = null;
        foreach ($elements as $element) {
            /** @var XmlElement $annot */
            $annot = $element->getAnnotation();
            if ($annot->getName() == $tagName) {
                $bindAnnotation = $annot;
                break;
            }
        }

        if (!$bindAnnotation && empty($anyElement)) {
            throw new NodeBindDoesNotExist('There is no bind for tag ' . $tagName);
        }

        if ($parent instanceof XmlNode) {
            $node = new XmlNode($tagName);
            $parent->addChildren($node);
            return [$node, $parentDef];
        } else if ($parent instanceof ScalarPropertyValue) {
            self::logError('Parent Tag is mapped to a Scalar Type but it is a complex type "'
                . $tagName . '"'
            );
            return [null, null];
        }


        $variables = $parentDef->getClassResource()->getVariables();
        if ($bindAnnotation) {
            /** @var XmlElement $annot */
            $annot = $bindAnnotation;
            if ($annot->getRefOwner() instanceof RefProperty) {
                $resource = $variables[$annot->getRefOwner()->getName()];
                $type = $resource->getParameterType();

                if ($annot->isList()) {
                    return $this->initElementListObject($resource, $parent, $annot);

                } else if ($type->getName() == 'array') {
                    $node = $this->createXmlNodeArray($resource, $parent, $tagName);

                    return [$node, $parentDef];
                } else if ($type->getName() == XmlNode::class) {
                    $node = $this->createXmlNode($resource, $parent, $tagName);

                    return [$node, $parentDef];
                } else if ($type->isNoType()
                    || $type->isUnionType()
                    || $type->isBuiltin()
                    || $type->getName() == DateTimeInterface::class
                    || $type->getName() == DateTime::class
                    || $annot->getValueAdapter()
                ) {
                    return [
                        new ScalarPropertyValue(
                            $resource,
                            $parent,
                            $annot
                        ),
                        $parentDef
                    ];
                }
                return $this->initElementObject($resource, $parent);
            }
        }

        if (!empty($anyElement)) {
            $any = $anyElement[0];
            if ($any->getAnnotation()->getRefOwner() instanceof RefProperty) {
                $resource = $variables[$any->getAnnotation()->getRefOwner()->getName()];
                $node = $this->createXmlNodeArray($resource, $parent, $tagName);
                return [$node, $parentDef];
            }
        }

        return [null, null];
    }

    protected function initElementListObject(
        VariableResource $resource,
        object $parent,
        XmlElement $annot
    ): array {
        $childCls = RefKlass::getInstance($annot->getListClass());
        if (!isset($this->resources[$childCls->getName()])) {
            return [null, null];
        }
        $objCls = $this->resources[$childCls->getName()];
        $obj = $childCls->newInstance();

        $var = $resource->getVariable();
        $var->setAccessible(true);

        try {
            $val = $var->getValue($parent);
        } catch (Throwable $e) {
            self::logDebug('initializing bean array ' . $e->getMessage());
            $val = [];
        }
        $val[] = $obj;
        $var->setValue($parent, $val);

        return [$obj, $objCls];
    }

    protected function initElementObject(VariableResource $resource, object $parent): array {
        $type = $resource->getParameterType();

        $childCls = RefKlass::getInstance($type->getName());
        if (!isset($this->resources[$childCls->getName()])) {
            return [null, null];
        }
        $objCls = $this->resources[$childCls->getName()];
        $obj = $childCls->newInstance();

        $var = $resource->getVariable();
        $var->setAccessible(true);
        $var->setValue($parent, $obj);
        return [$obj, $objCls];
    }

    private function createXmlNode(VariableResource $resource, object $obj, string $tagName): XmlNode {
        $node = new XmlNode($tagName);

        $var = $resource->getVariable();
        $var->setAccessible(true);
        $var->setValue($obj, $node);
        return $node;
    }

    private function createXmlNodeArray(VariableResource $resource, object $obj, string $tagName): XmlNode {
        $node = new XmlNode($tagName);

        $var = $resource->getVariable();
        $var->setAccessible(true);

        try {
            $val = $var->getValue($obj);
        } catch (Throwable $e) {
            self::logDebug('initializing bean array ' . $e->getMessage());
            $val = [];
        }
        $val[] = $node;
        $var->setValue($obj, $val);
        return $node;
    }

    /**
     * Assign XML Tag Value to Object
     *
     * @param object $obj
     * @param XmlBeanAnnotations $objDef
     */
    protected function mapXmlValue(object $obj, XmlBeanAnnotations $objDef): void {

        if ($obj instanceof XmlNode) {
            $obj->setValue($this->reader->value);
            return;
        } else if ($obj instanceof ScalarPropertyValue) {
            $this->setObjectProperty(
                $obj->getResource(),
                $obj->getObject(),
                $this->reader->value,
                $obj->getAnnotation()
            );
            return;
        }

        $attrList = $objDef->getAttributesBy(XmlValue::class);
        foreach ($attrList as $attr) {
            $resource = $attr->getResource();
            /** @var XmlValue $xmlVal */
            $xmlVal = $attr->getAnnotation();
            $this->setObjectProperty(
                $resource,
                $obj,
                $this->reader->value,
                $xmlVal
            );
        }
    }

    /**
     * Map XML Tag Attributes to Object
     *
     * @param object $obj
     * @param XmlBeanAnnotations $attrHolder
     */
    protected function mapXmlAttributes(object $obj, XmlBeanAnnotations $attrHolder): void {

        $properties = [];
        if ($this->reader->hasAttributes) {
            while ($this->reader->moveToNextAttribute()) {
                $properties[$this->reader->name] = $this->reader->value;
            }
        }

        if ($obj instanceof XmlNode) {
            $obj->setProperties($properties);
            return;
        } else if ($obj instanceof ScalarPropertyValue) {
            // Nothing to do
            return;
        }

        $attrList = $attrHolder->getAttributesBy(XmlAttribute::class);
        $anyAttrList = $attrHolder->getAttributesBy(XmlAnyAttribute::class);

        if (count($attrList) == 0 && count($anyAttrList) == 0) {
            return;
        }

        foreach ($attrList as $attr) {
            /** @var XmlAttribute $annotation */
            $annotation = $attr->getAnnotation();
            $resource = $attr->getResource();

            if (isset($properties[$annotation->getName()])) {
                if ($resource instanceof VariableResource) {
                    $this->setObjectProperty(
                        $resource,
                        $obj,
                        $properties[$annotation->getName()],
                        $annotation
                    );
                }
            } else if ($annotation->isRequired()) {
                throw new PaxbException('#[XmlAttribute] ' . $annotation->getName() . ' on resource is required "'
                    . ReflectionUtil::getFqName($resource)
                    . '", but could not find it in the source XML '
                );
            }

            // already set , so discard it!
            unset($properties[$annotation->getName()]);
        }

        if (count($anyAttrList) > 0 && !empty($properties)) {
            $any = $anyAttrList[0];
            $anyResource = $any->getResource();
            if ($anyResource instanceof VariableResource) {
                $this->setObjectAnyProperty($anyResource, $obj, $properties);
            }
        }

        // Move cursor back to Element from attribute
        $this->reader->moveToElement();
    }

    protected function getValueAdapter(string $adapterClass): XmlValueAdapter {
        if (isset($this->valueAdapters[$adapterClass])) {
            return $this->valueAdapters[$adapterClass];
        }

        return $this->valueAdapters[$adapterClass] = new $adapterClass();
    }

    /**
     * Add unknown XML Tag Attributes to #[XmlAnyAttribute] handled variable
     *
     * @param VariableResource $variable
     * @param object $obj
     * @param array $properties
     */
    protected function setObjectAnyProperty(VariableResource $variable, object $obj, array $properties): void {
        $var = $variable->getVariable();
        $var->setAccessible(true);

        // This must be Array always
        $var->setValue($obj, []);
        $val = $var->getValue($obj);

        foreach ($properties as $prop => $value) {
            $val[$prop] = $value;
        }

        $var->setValue($obj, $val);
    }

    /**
     * Set Object property from XML Tag attribute value
     */
    /**
     * @param VariableResource $variable
     * @param object $obj
     * @param string $value
     * @param mixed|XmlAttribute|XmlElement|XmlValue $annotation
     */
    protected function setObjectProperty(
        VariableResource $variable,
        object $obj,
        string $value,
        mixed $annotation = null
    ): void {
        if ($annotation) {
            if ($annotation->getFilters()) {
                foreach ($annotation->getFilters() as $filter => $filterVal) {
                    switch ($filter) {
                        case XmlValue::FILTER_TRIM:
                            if ($filterVal) {
                                $value = trim($value);
                            }
                            break;

                        case XmlValue::FILTER_LENGTH:
                            if ($filterVal && is_numeric($filterVal)) {
                                $value = substr($value, 0, $filterVal);
                            }
                            break;

                        case XmlValue::FILTER_LOWERCASE:
                            if ($filterVal) {
                                $value = strtolower($value);
                            }
                            break;

                        case XmlValue::FILTER_UPPERCASE:
                            if ($filterVal) {
                                $value = strtoupper($value);
                            }
                            break;
                    }
                }
            }

            if ($annotation->getValueAdapter()) {
                $adapter = $this->getValueAdapter($annotation->getValueAdapter());
                $value = $adapter->unmarshal($value);
            }
        }

        $var = $variable->getVariable();
        self::doSetObjectProperty($var, $obj, $value, ObjectMapper::SOURCE_XML);
    }
}