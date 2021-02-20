<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\support;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class MethodParameters extends ArrayList {

    public function offsetGet($offset): ?MethodParameter {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var MethodParameter $value */
        TypeAssert::typeOf($value, MethodParameter::class);
        parent::offsetSet($value->getName(), $value);
    }
}
