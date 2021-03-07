<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class VariableResources extends ArrayList {
    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?VariableResource {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var VariableResource $value */
        TypeAssert::typeOf($value, VariableResource::class);
        $offset = $value->getVariable()->getName();
        parent::offsetSet($offset, $value);
    }
}