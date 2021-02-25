<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\type;

class StringList extends ArrayList {

    public function offsetGet($offset): ?string {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::string($value);
        parent::offsetSet($offset, $value);
    }

}