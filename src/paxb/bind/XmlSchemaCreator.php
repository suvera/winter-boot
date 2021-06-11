<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use DateTime;
use DateTimeInterface;
use dev\winterframework\paxb\attr\XmlAnyAttribute;
use dev\winterframework\paxb\attr\XmlAnyElement;
use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlPropertyOrder;
use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;
use dev\winterframework\paxb\ex\PaxbException;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\util\log\Wlf4p;

class XmlSchemaCreator {
    use Wlf4p;
    use XmlResourceScanner;

    /**
     * @var XmlBeanAnnotations[]
     */
    protected array $resources;

    protected RefKlass $rootClass;

    /**
     * @var XmlBeanAnnotations[]
     */
    protected array $classTypes = [];

    public function __construct(
        protected string $class
    ) {
        $this->rootClass = RefKlass::getInstance($this->class);
    }

    public function getResources(): array {
        return $this->resources;
    }

    public function buildXsd(): string {
        $this->resources = $this->scanForPaxbClasses($this->rootClass);
        $this->validate();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" elementFormDefault="qualified">' . PHP_EOL;

        $xml .= $this->buildClassTypes();

        $xml .= '</xsd:schema>';
        return $xml;
    }

    protected function buildClassTypes(): string {
        $xml = '';

        foreach ($this->resources as $classDef) {
            $xml .= $this->buildClassType($classDef);
        }

        return $xml;
    }

    protected function buildClassType(XmlBeanAnnotations $classDef): string {
        $xml = '';

        /** @var XmlBeanAnnotation[] $elements */
        $elements = $classDef->getAttributesBy(XmlElement::class);
        /** @var XmlBeanAnnotation[] $anyElement */
        $anyElement = $classDef->getAttributesBy(XmlAnyElement::class);

        /** @var XmlBeanAnnotation[] $xmlValue */
        $xmlValue = $classDef->getAttributesBy(XmlValue::class);

        /** @var XmlBeanAnnotation[] $attributes */
        $attributes = $classDef->getAttributesBy(XmlAttribute::class);
        /** @var XmlBeanAnnotation[] $anyAttrs */
        $anyAttr = $classDef->getAttributesBy(XmlAnyAttribute::class);

        /** @var XmlPropertyOrder $propOrder */
        $propOrder = $classDef->getClassResource()->getAttribute(XmlPropertyOrder::class);

        /** @var XmlRootElement $rootElement */
        $rootElement = $classDef->getClassResource()->getAttribute(XmlRootElement::class);

        $className = $classDef->getClassResource()->getClass()->getName();
        $shortName = $classDef->getClassResource()->getClass()->getShortName();

        $isRoot = ($this->rootClass->getName() == $className && !is_null($rootElement));
        $nameTag = 'name="' . $shortName . '" ';
        if ($isRoot) {
            $nameTag = '';
            $xml .= '<xsd:element name="' . $rootElement->getName() . '">' . PHP_EOL;
        }

        $hasElements = (count($elements) > 0 || count($anyElement) > 0);
        $hasAttributes = (count($attributes) > 0 || count($anyAttr) > 0);
        $hasXmlValue = (count($xmlValue) > 0);

        if (!$hasElements && !$hasAttributes) {
            if ($hasXmlValue) {
                $valObj = $xmlValue[0];
                $paramType = $valObj->getResource()->getParameterType();
                $xml .= '<xsd:simpleType ' . $nameTag . '>' . PHP_EOL;
                $xml .= '<xsd:restriction base="' . $this->getXsType($paramType) . '"/>' . PHP_EOL;
                $xml .= '</xsd:simpleType>' . PHP_EOL;
            }
        } else if (!$hasElements && $hasAttributes) {
            $xml .= '<xsd:complexType ' . $nameTag . '>' . PHP_EOL;
            $xml .= '<xsd:simpleContent>' . PHP_EOL;

            if ($hasXmlValue) {
                $valObj = $xmlValue[0];
                $paramType = $valObj->getResource()->getParameterType();
                $xml .= '<xsd:extension base="' . $this->getXsType($paramType) . '">' . PHP_EOL;
            }

            foreach ($attributes as $attr) {
                /** @var XmlAttribute $annot */
                $annot = $attr->getAnnotation();
                $paramType = $attr->getResource()->getParameterType();
                $xml .= '<xsd:attribute name="' . $annot->getName()
                    . '" type="' . $this->getXsType($paramType) . '"/>' . PHP_EOL;
            }

            if (!empty($anyAttr)) {
                $xml .= '<xsd:anyAttribute/>' . PHP_EOL;
            }

            if ($hasXmlValue) {
                $xml .= '</xsd:extension>' . PHP_EOL;
            }

            $xml .= '</xsd:simpleContent>' . PHP_EOL;
            $xml .= '</xsd:complexType>' . PHP_EOL;

        } else if ($hasElements) {

            $xml .= '<xsd:complexType ' . $nameTag . '>' . PHP_EOL;
            $xml .= '<xsd:sequence>' . PHP_EOL;

            $namedElts = [];
            foreach ($elements as $element) {
                /** @var XmlElement $annot */
                $annot = $element->getAnnotation();
                $paramType = $element->getResource()->getParameterType();

                if ($annot->isList()) {
                    $type = $this->getClassShortName($annot->getListClass());
                } else {
                    $type = $this->getXsType($paramType);
                }
                $namedElts[$annot->getName()] = '<xsd:element '
                    . 'name="' . $annot->getName() . '" '
                    . 'type="' . $type . '" '
                    . 'minOccurs="' . ($annot->isRequired() ? '1' : '0') . '" '
                    . 'nillable="' . ($annot->isNillable() ? 'true' : 'false') . '" '
                    . 'maxOccurs="' . ($annot->isList() ? 'unbounded' : '1') . '"/>';
            }

            if (!is_null($propOrder)) {
                $order = $propOrder->getOrder();
                foreach ($order as $eltName) {
                    if (!isset($namedElts[$eltName])) {
                        if (!$propOrder->isIgnoreUnknown()) {
                            throw new PaxbException("Xml Element '$eltName' not found in the XML Object "
                                . ' but added in the #[XmlPropertyOrder] attribute at the class '
                                . ReflectionUtil::getFqName($classDef->getClassResource()));
                        }
                    } else {
                        $xml .= $namedElts[$eltName] . PHP_EOL;
                        unset($namedElts[$eltName]);
                    }
                }
            }
            $xml .= implode(PHP_EOL, $namedElts) . PHP_EOL;
            if (count($anyElement) > 0) {
                $xml .= '<xsd:any minOccurs="0" maxOccurs="unbounded" processContents="skip"/>' . PHP_EOL;
            }
            $xml .= '</xsd:sequence>' . PHP_EOL;

            foreach ($attributes as $attr) {
                /** @var XmlAttribute $annot */
                $annot = $attr->getAnnotation();
                $paramType = $attr->getResource()->getParameterType();
                $xml .= '<xsd:attribute name="' . $annot->getName()
                    . '" type="' . $this->getXsType($paramType) . '"/>' . PHP_EOL;
            }

            if (!empty($anyAttr)) {
                $xml .= '<xsd:anyAttribute/>' . PHP_EOL;
            }

            $xml .= '</xsd:complexType>' . PHP_EOL;
        }

        if ($isRoot) {
            $xml .= '</xsd:element>' . PHP_EOL;
        }

        return $xml;
    }

    protected function getXsType(ParameterType $paramType): string {

        if ($paramType->isBuiltin() || $paramType->isNoType() || $paramType->isVoidType()) {
            return match ($paramType->getName()) {
                'int' => 'xsd:integer',
                'float' => 'xsd:decimal',
                'bool' => 'xsd:boolean',
                DateTimeInterface::class, DateTime::class => 'xsd:date',
                default => 'xsd:string',
            };
        }

        return $this->getClassShortName($paramType->getName());
    }

    protected function getClassShortName(string $clsName): string {
        if (is_a($clsName, XmlScalar::class, true)) {
            return $clsName::getXsdType();
        }
        $cls = RefKlass::getInstance($clsName);
        return $cls->getShortName();
    }
}