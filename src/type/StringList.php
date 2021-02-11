<?php
/** @noinspection PhpUnused */
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\type;

final class StringList extends ArrayList {
    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?string {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::string($value);
        parent::offsetSet($offset, $value);
    }

    public static function ofArray(array $values): StringList {
        return parent::ofArray($values);
    }

    public static function ofValues(mixed ...$values): StringList {
        return parent::ofArray($values);
    }

}