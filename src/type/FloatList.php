<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\type;

final class FloatList extends ArrayList {

    public function offsetGet($offset): ?float {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::float($value);
        parent::offsetSet($offset, $value);
    }

}
