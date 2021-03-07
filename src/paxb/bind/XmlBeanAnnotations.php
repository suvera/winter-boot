<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlElementWrapper;
use dev\winterframework\reflection\ClassResource;

class XmlBeanAnnotations {
    /**
     * @var XmlBeanAnnotation[][]
     */
    protected array $attributes = [];

    public function __construct(
        protected ClassResource $classResource
    ) {
        $this->process();
    }

    public function getClassResource(): ClassResource {
        return $this->classResource;
    }

    public function getAttributes(): array {
        return $this->attributes;
    }

    public function getAttributesBy(string $className): array {
        return isset($this->attributes[$className]) ? $this->attributes[$className] : [];
    }

    private function mergeToAttributes(array $local): void {
        foreach ($local as $attrCls => $list) {
            if (isset($this->attributes[$attrCls])) {
                $this->attributes[$attrCls] = array_merge($this->attributes[$attrCls], $list);
            } else {
                $this->attributes[$attrCls] = $list;
            }
        }
    }

    protected function process(): void {

        $wrappers = [
            XmlElement::class => XmlElementWrapper::class
        ];

        foreach ($this->classResource->getMethods() as $method) {

            $local = [];
            foreach ($method->getAttributes() as $attribute) {
                $attrCls = $attribute::class;

                if (isset($local[$attrCls])) {
                    $local[$attrCls][] = new XmlBeanAnnotation($attribute, $method);
                } else {
                    $local[$attrCls] = [
                        new XmlBeanAnnotation($attribute, $method)
                    ];
                }
            }
            foreach ($wrappers as $child => $wrappedBy) {
                if (isset($local[$child]) && isset($local[$wrappedBy])) {
                    /** @var XmlElementWrapper $wrapAnnot */
                    $wrapAnnot = $local[$wrappedBy][0]->getAnnotation();
                    /** @var XmlElement $childAnnot */
                    $childAnnot = $local[$child][0]->getAnnotation();
                    $childAnnot->setWrapper($wrapAnnot);
                }
            }
            $this->mergeToAttributes($local);
        }

        foreach ($this->classResource->getVariables() as $variable) {
            $local = [];
            foreach ($variable->getAttributes() as $attribute) {
                $attrCls = $attribute::class;

                if (isset($local[$attrCls])) {
                    $local[$attrCls][] = new XmlBeanAnnotation($attribute, $variable);
                } else {
                    $local[$attrCls] = [
                        new XmlBeanAnnotation($attribute, $variable)
                    ];
                }
            }
            $this->mergeToAttributes($local);
        }
    }

}