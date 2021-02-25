<?php
declare(strict_types=1);

namespace dev\winterframework\core\data;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class Options extends ArrayList {
    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?Option {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, Option::class);
        /** @var $value Option */
        $offset = $value->getId();
        
        parent::offsetSet($offset, $value);
    }
}