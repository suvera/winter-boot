<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

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

    /**
     * @return XmlBeanAnnotation[][]
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

    /**
     * @param string $className
     * @return XmlBeanAnnotation[]
     */
    public function getAttributesBy(string $className): array {
        return $this->attributes[$className] ?? [];
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