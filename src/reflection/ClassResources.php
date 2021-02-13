<?php
declare(strict_types=1);


namespace dev\winterframework\reflection;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\Arrays;
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

    public function offsetGet($offset): ?ClassResource {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var ClassResource $value */
        TypeAssert::typeOf($value, ClassResource::class);
        parent::offsetSet($offset, $value);
        foreach ($value->getAttributes() as $attribute) {
            $attrType = $attribute::class;
            if (!isset($this->byAttributes[$attrType])) {
                $this->byAttributes[$attrType] = [];
            }
            $this->byAttributes[$attrType][] = $attribute;
        }
    }

    public function hasAttribute(string $attrCls): bool {
        return isset($this->byAttributes[$attrCls]);
    }

    public function getClassesByAttribute(string $attrCls): array {
        return isset($this->byAttributes[$attrCls]) ? $this->byAttributes[$attrCls] : Arrays::$EMPTY_ARRAY;
    }

    public function get1stClassByAttribute(string $attrCls): ?ClassResource {
        return isset($this->byAttributes[$attrCls]) ? $this->byAttributes[$attrCls][0] : null;
    }
}