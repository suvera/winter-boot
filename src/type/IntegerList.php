<?php
/** @noinspection PhpUnused */
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\type;

final class IntegerList extends ArrayList {
    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?int {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::integer($value);
        parent::offsetSet($offset, $value);
    }


    public static function ofArray(array $values): IntegerList {
        return parent::ofArray($values);
    }

    public static function ofValues(mixed ...$values): IntegerList {
        return parent::ofArray($values);
    }

}