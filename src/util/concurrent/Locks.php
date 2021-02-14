<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class Locks extends ArrayList {

    public function offsetGet($offset): ?Lock {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, Lock::class);
        parent::offsetSet($offset, $value);
    }
}