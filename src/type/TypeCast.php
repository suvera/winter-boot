<?php

declare(strict_types=1);

namespace dev\winterframework\type;

class TypeCast {

    public static function parseValue(string $type, string $val): float|null|bool|int|string {
        if ("null" === $val) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => intval($val),
            'bool', 'boolean' => boolval($val),
            'float', 'double' => floatval($val),
            default => $val,
        };
    }
}