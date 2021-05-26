<?php
declare(strict_types=1);


namespace dev\winterframework\reflection;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\AttributeList;
use dev\winterframework\type\TypeAssert;

class ClassResources extends ArrayList {

    /**
     * @var ClassResource[][]
     */
    private array $byAttributes = [];

    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function clear(): void {
        parent::clear();
        $this->byAttributes = [];
    }

    public function offsetGet($offset): ?ClassResource {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var ClassResource $value */
        TypeAssert::typeOf($value, ClassResource::class);

        $offset = $value->getClass()->getName();

        parent::offsetSet($offset, $value);

        $this->addByAttributes($value, $value->getAttributes());
        foreach ($value->getMethods() as $meth) {
            $this->addByAttributes($value, $meth->getAttributes());
        }
        foreach ($value->getVariables() as $var) {
            $this->addByAttributes($value, $var->getAttributes());
        }
    }

    public function hasAttribute(string $attrCls): bool {
        return isset($this->byAttributes[$attrCls]);
    }

    public function getClassesByAttribute(string $attrCls): array {
        return $this->byAttributes[$attrCls] ?? Arrays::$EMPTY_ARRAY;
    }

    public function get1stClassByAttribute(string $attrCls): ?ClassResource {
        return isset($this->byAttributes[$attrCls]) ? $this->byAttributes[$attrCls][0] : null;
    }

    public function merge(self $other): void {
        foreach ($other as $row) {
            $this[] = $row;
        }
    }

    protected function addByAttributes(ClassResource $cls, AttributeList $attrs) {
        foreach ($attrs as $attribute) {
            $attrType = $attribute::class;
            if (!isset($this->byAttributes[$attrType])) {
                $this->byAttributes[$attrType] = [];
            }
            $this->byAttributes[$attrType][] = $cls;
        }
    }
}