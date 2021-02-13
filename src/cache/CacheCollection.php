<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class CacheCollection extends ArrayList {

    public function offsetGet($offset): ?Cache {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, Cache::class);
        parent::offsetSet($offset, $value);
    }

}