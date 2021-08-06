<?php

declare(strict_types=1);

namespace dev\winterframework\type;

class TypeCast {

    public static function parseValue(string $type, mixed $val): mixed {
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

    public static function toString(mixed $val): ?string {
        $type = gettype($val);

        return match ($type) {
            'null' => null,
            'bool', 'boolean' => $val ? 'true' : 'false',
            default => strval($val),
        };
    }
}