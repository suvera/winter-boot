<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\type;

final class IntegerList extends ArrayList {

    public function offsetGet($offset): ?int {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::integer($value);
        parent::offsetSet($offset, $value);
    }

}