<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class Psr4Namespaces extends ArrayList {
    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?Psr4Namespace {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, Psr4Namespace::class);
        parent::offsetSet($offset, $value);
    }

    public static function ofArrayItems(array $values): Psr4Namespaces {
        $obj = new self();

        foreach ($values as $row) {
            $obj[] = new Psr4Namespace($row[0], $row[1]);
        }

        return $obj;
    }
}