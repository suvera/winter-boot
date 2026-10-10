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

    /**
     * Converts a configuration value (yml, $env, $ini, ...) to a scalar
     * property type. Unlike parseValue() it reads booleans by meaning
     * ("false", "no", "off", "0" are false) and refuses values that don't
     * fit instead of guessing (intval("abc") would be 0).
     *
     * Values that already have the target type pass through unchanged;
     * non-scalar targets (array, mixed, untyped) are not converted.
     *
     * @throws \UnexpectedValueException when the value doesn't fit; the
     *         message never contains the value (config can hold secrets)
     */
    public static function parseConfigValue(string $type, mixed $val): mixed {
        if ($val === null) {
            return null;
        }
        // "null" (any case) means no value, except for strings, where it may be meant literally.
        if (is_string($val) && strtolower(trim($val)) === 'null'
            && !in_array(strtolower($type), ['string', 'mixed', ''], true)) {
            return null;
        }

        switch (strtolower($type)) {
            case 'bool':
            case 'boolean':
                if (is_bool($val)) {
                    return $val;
                }
                if ($val === 0 || $val === 1) {
                    return $val === 1;
                }
                if (is_string($val)) {
                    $s = strtolower(trim($val));
                    if (in_array($s, ['true', '1', 'yes', 'on'], true)) {
                        return true;
                    }
                    if (in_array($s, ['false', '0', 'no', 'off', ''], true)) {
                        return false;
                    }
                }
                throw new \UnexpectedValueException('expected a boolean (true/false, yes/no, on/off, 1/0)');

            case 'int':
            case 'integer':
                if (is_int($val)) {
                    return $val;
                }
                if (is_float($val) && floor($val) === $val && abs($val) <= PHP_INT_MAX) {
                    return (int)$val;
                }
                if (is_string($val) && preg_match('/^\s*([+-]?)(\d+)\s*$/', $val, $m)) {
                    $digits = ltrim($m[2], '0');
                    $normalized = $digits === '' ? '0' : ($m[1] === '-' ? '-' : '') . $digits;
                    $i = (int)$normalized;
                    // (int) saturates on overflow; a round trip proves the value fits.
                    if ((string)$i !== $normalized) {
                        throw new \UnexpectedValueException('integer out of range');
                    }
                    return $i;
                }
                throw new \UnexpectedValueException('expected an integer');

            case 'float':
            case 'double':
                if (is_float($val) || is_int($val)) {
                    return (float)$val;
                }
                if (is_string($val) && is_numeric(trim($val))) {
                    return (float)trim($val);
                }
                throw new \UnexpectedValueException('expected a number');

            case 'string':
                if (is_string($val)) {
                    return $val;
                }
                if (is_bool($val)) {
                    return $val ? 'true' : 'false';
                }
                if (is_int($val) || is_float($val)) {
                    return (string)$val;
                }
                throw new \UnexpectedValueException('expected a string');

            default:
                return $val;
        }
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