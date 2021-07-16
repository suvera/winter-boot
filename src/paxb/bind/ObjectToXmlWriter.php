<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlAnyAttribute;
use dev\winterframework\paxb\attr\XmlAnyElement;
use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlPropertyOrder;
use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;
use dev\winterframework\paxb\XmlValueAdapter;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\log\Wlf4p;
use Throwable;
use XMLWriter;

class ObjectToXmlWriter {
    use Wlf4p;
    use XmlResourceScanner;

    /**
     * @var XmlValueAdapter[]
     */
    protected array $valueAdapters = [];

    public function __construct(
        protected XMLWriter $writer,
        protected object $object,
        /**
         * @var XmlBeanAnnotations[]
         */
        protected array $resources = []
    ) {
    }

    public function getWriter(): XMLWriter {
        return $this->writer;
    }

    protected function getValueAdapter(string $adapterClass): XmlValueAdapter {
        if (isset($this->valueAdapters[$adapterClass])) {
            return $this->valueAdapters[$adapterClass];
        }

        return $this->valueAdapters[$adapterClass] = new $adapterClass();
    }

    public function create(): void {
        $cls = new RefKlass($this->object);

        if (empty($this->resources)) {
            $this->resources = $this->scanForPaxbClasses($cls);
        }

        $root = $cls->getAttributes(XmlRootElement::class);

        if ($root) {
            /** @var XmlRootElement $attr */
            $attr = ReflectionUtil::createAttribute($root[0], $cls);
            $rootName = $attr->getName();
        } else {
            $rootName = $cls->getShortName();
        }

        /** @var XmlBeanAnnotations $curXmlCls */
        if (!isset($this->resources[$cls->getName()])) {
            self::logInfo("Given class is not annotated with XmlRootElement");
            return;
        }
        $beanCls = $this->resources[$cls->getName()];

        $this->buildForClass($rootName, $beanCls, $this->object);
    }

    protected function buildForClass($tagName, XmlBeanAnnotations $xmlBean, object $object): void {
        $this->writer->startElement($tagName);

        $xmlAttrs = $xmlBean->getAttributesBy(XmlAttribute::class);
        $xmlAnyAttrs = $xmlBean->getAttributesBy(XmlAnyAttribute::class);
        /**
         * Process XML Attributes
         */
        $this->processXmlAttributes($xmlAttrs, $object);
        if ($xmlAnyAttrs) {
            $this->processXmlAnyAttributes($xmlAnyAttrs[0], $object);
        }

        $order = $xmlBean->getClassResource()->getAttribute(XmlPropertyOrder::class);
        $ordered = [];
        if ($order) {
            /** @var XmlPropertyOrder $orderAttr */
            $orderAttr = ReflectionUtil::createAttribute($order[0], $xmlBean->getClassResource());
            $ordered = $orderAttr->getOrder();
        }

        $elements = $xmlBean->getAttributesBy(XmlElement::class);
        $this->processXmlElements($elements, $object, $ordered);

        $anyElements = $xmlBean->getAttributesBy(XmlAnyElement::class);
        if ($anyElements) {
            $this->processXmlAnyElement($anyElements[0], $object);
        }

        $xmlValues = $xmlBean->getAttributesBy(XmlValue::class);
        if ($xmlValues) {
            $xmlValue = $xmlValues[0];
            $this->processXmlValue($xmlValue, $object);
        }

        $this->writer->endElement();
    }

    protected function processXmlAttributes(array $xmlAttrs, object $object): void {
        foreach ($xmlAttrs as $xmlAttr) {
            /** @var XmlBeanAnnotation $xmlAttr */
            /** @var XmlAttribute $attr */
            $attr = $xmlAttr->getAnnotation();
            $property = $attr->getRefOwner();
            $property->setAccessible(true);

            try {
                $value = $property->getValue($object);
            } catch (Throwable $e) {
                self::logEx($e);
                continue;
            }

            if (is_null($value) && !$attr->isRequired()) {
                continue;
            }

            if ($attr->getValueAdapter()) {
                $adapter = $this->getValueAdapter($attr->getValueAdapter());
                $value = $adapter->marshal($value);
            }

            $this->writer->startAttribute($attr->getName());
            $this->writer->text('' . $value);
            $this->writer->endAttribute();
        }
    }

    protected function processXmlValue(XmlBeanAnnotation $xmlValue, object $object): void {
        /** @var XmlValue $attr */
        $attr = $xmlValue->getAnnotation();
        $property = $attr->getRefOwner();
        $property->setAccessible(true);

        $err = false;
        try {
            $value = $property->getValue($object);
        } catch (Throwable $e) {
            self::logEx($e);
            $value = '';
            $err = true;
        }

        if (!$err && $attr->getValueAdapter()) {
            $adapter = $this->getValueAdapter($attr->getValueAdapter());
            $value = $adapter->marshal($value);
        }

        if ($attr->isCData()) {
            $this->writer->writeCdata('' . $value);
        } else {
            $this->writer->text('' . $value);
        }
    }

    protected function processXmlAnyAttributes(XmlBeanAnnotation $xmlAnyAttr, object $object): void {

        /** @var XmlAnyAttribute $attr */
        $attr = $xmlAnyAttr->getAnnotation();
        $property = $attr->getRefOwner();

        $property->setAccessible(true);

        try {
            $value = $property->getValue($object);
        } catch (Throwable $e) {
            self::logEx($e);
            return;
        }

        if (is_null($value) && !$attr->isRequired()) {
            return;
        }

        foreach ($value as $key => $val) {
            $this->writer->startAttribute($key);
            $this->writer->text('' . $val);
            $this->writer->endAttribute();
        }
    }

    protected function processXmlElements(array $elements, object $object, array $ordered): void {
        /** @var XmlBeanAnnotation[] $elements */
        /** @var XmlElement[] $xmlElements */
        $xmlElements = [];
        if ($ordered) {
            $list = [];
            foreach ($elements as $element) {
                $list[$element->getName()] = $element->getAnnotation();
            }
            foreach ($ordered as $name) {
                if (isset($list[$name])) {
                    $xmlElements[] = $list[$name];
                    unset($list[$name]);
                }
            }
            foreach ($list as $elt) {
                $xmlElements[] = $elt;
            }
        } else {
            foreach ($elements as $element) {
                $xmlElements[] = $element->getAnnotation();
            }
        }
        foreach ($xmlElements as $element) {
            $property = $element->getRefOwner();
            $property->setAccessible(true);

            try {
                $value = $property->getValue($object);
            } catch (Throwable $e) {
                self::logEx($e);
                continue;
            }

            if (is_null($value) && !$element->isRequired()) {
                continue;
            }

            if ($element->isList()) {
                if (!is_array($value)) {
                    continue;
                }
                $cls = new RefKlass($element->getListClass());
                /** @var XmlBeanAnnotation $beanCls */
                if (!isset($this->resources[$cls->getName()])) {
                    self::logError('Could not find XML bean class ' . $cls->getName());
                    continue;
                }
                $beanCls = $this->resources[$cls->getName()];

                foreach ($value as $item) {
                    $this->buildForClass($element->getName(), $beanCls, $item);
                }
            } else {
                $this->writer->startElement($element->getName());
                if ($element->getValueAdapter()) {
                    $adapter = $this->getValueAdapter($element->getValueAdapter());
                    $value = $adapter->marshal($value);
                }
                $this->writer->text('' . $value);
                $this->writer->endElement();
            }
        }
    }

    protected function processXmlAnyElement(XmlBeanAnnotation $anyElement, object $object): void {
        $property = $anyElement->getAnnotation()->getRefOwner();
        $property->setAccessible(true);

        try {
            $value = $property->getValue($object);
        } catch (Throwable $e) {
            self::logEx($e);
            return;
        }

        if (is_null($value)) {
            return;
        }

        foreach ($value as $node) {
            if ($node instanceof XmlNode) {
                $this->processXmlNode($node);
            }
        }
    }

    protected function processXmlNode(XmlNode $node): void {
        $this->writer->startElement($node->getName());

        foreach ($node->getProperties() as $key => $val) {
            $this->writer->startAttribute($key);
            $this->writer->text('' . $val);
            $this->writer->endAttribute();
        }

        if ($node->getValue() !== '') {
            $this->writer->text($node->getValue());
        }

        foreach ($node->getChildren() as $child) {
            $this->processXmlNode($child);
        }

        $this->writer->endElement();
    }

}