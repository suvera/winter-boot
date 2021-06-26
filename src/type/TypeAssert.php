<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\type;

use dev\winterframework\exception\IllegalStateException;
use TypeError;

final class TypeAssert {
    private static array $SCALAR_TYPES = [
        'bool' => 'bool',
        'int' => 'int',
        'float' => 'float',
        'string' => 'string',

        // gettype() has BC values
        'boolean' => 'boolean',
        'double' => 'double',
        'integer' => 'integer'
    ];

    /*
     *  mixed is equivalent to the union type
     *         array|bool|callable|int|float|object|resource|string|null
     *
     */
    public static function string(mixed $value, string $msg = '') {
        if (!is_string($value)) {
            throw new TypeError("Expected data type String, but got " . gettype($value) . $msg);
        }
    }

    public static function integer(mixed $value, string $msg = '') {
        if (!is_int($value)) {
            throw new TypeError("Expected data type Integer, but got " . gettype($value) . $msg);
        }
    }

    public static function boolean(mixed $value) {
        if (!is_bool($value)) {
            throw new TypeError("Expected data type Boolean, but got " . gettype($value));
        }
    }

    public static function float(mixed $value) {
        if (!is_float($value)) {
            throw new TypeError("Expected data type Float, but got " . gettype($value));
        }
    }

    public static function scalar(mixed $value) {
        if (!is_string($value) && !is_bool($value) && !is_float($value) && !is_int($value)) {
            throw new TypeError("Expected data type Scalar, but got " . gettype($value));
        }
    }

    public static function isScalarName(string $typeName, string $prefix = '') {
        if (!isset(self::$SCALAR_TYPES[$typeName])) {
            throw new TypeError($prefix . "Expected data type Scalar, but got " . $typeName);
        }
    }

    public static function isScalarOrArrayName(string $typeName, string $prefix = '') {
        if ($typeName !== 'array' && !isset(self::$SCALAR_TYPES[$typeName])) {
            throw new TypeError($prefix . "Expected data type Scalar or Array, but got " . $typeName);
        }
    }

    public static function positiveInteger(mixed $value) {
        self::integer($value);

        if ($value < 0) {
            throw new TypeError("Expected data type Positive Integer ( >= 0), but got " . $value);
        }
    }

    public static function positiveNoZeroInteger(mixed $value, string $msg = '') {
        self::integer($value);

        if ($value <= 0) {
            throw new TypeError($msg ? $msg : "Expected data type Positive Integer ( > 0), but got " . $value);
        }
    }

    public static function negativeInteger(mixed $value) {
        self::integer($value);

        if ($value > 0) {
            throw new TypeError("Expected data type Negative Integer ( <= 0), but got " . $value);
        }
    }

    public static function negativeNoZeroInteger(mixed $value) {
        self::integer($value);

        if ($value >= 0) {
            throw new TypeError("Expected data type Negative Integer ( < 0), but got " . $value);
        }
    }

    public static function callable(mixed $value) {
        if (!is_callable($value)) {
            throw new TypeError("Expected data type Callable, but got " . gettype($value));
        }
    }

    public static function array(mixed $value, string $msg = '') {
        if (!is_array($value)) {
            throw new TypeError("Expected data type Array, but got " . gettype($value) . $msg);
        }
    }

    public static function stringArray(array $value, string $msg = '') {
        array_walk($value, function ($val, $idx, $msg) {
            self::string($val, $msg);
        }, $msg);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public static function intArray(array $value, string $msg = '') {
        array_walk($value, function ($val, $idx, $msg) {
            self::integer($val, $msg);
        });
    }

    public static function object(mixed $value) {
        if (!is_object($value)) {
            throw new TypeError("Expected data type Array, but got " . gettype($value));
        }
    }

    public static function null(mixed $value) {
        if (!is_null($value)) {
            throw new TypeError("Expected data type Array, but got " . gettype($value));
        }
    }

    public static function typeOf(mixed $value, string ...$classNames) {
        foreach ($classNames as $className) {
            if ($value instanceof $className) {
                return;
            }
        }

        throw new TypeError("Expected data type is one of " . implode(', ', $classNames)
            . ", but got " . (is_object($value::class) ? $value::class : $value));
    }

    public static function objectOf(object $value, string $className) {
        if (!$value instanceof $className) {
            throw new TypeError("Expected data type " . $className . ", but got " . (is_object($value::class) ? $value::class : $value));
        }
    }

    public static function objectOfIsA(string $value, string $className, string $msg = '') {
        if (!is_a($value, $className, true)) {
            throw new TypeError($msg ? $msg : "Expected data type " . $className . ", but got " . $value);
        }
    }

    public static function notEmpty(string $name, mixed $value, string $msg = '') {
        if (!isset($value) || empty($value)) {
            throw new TypeError($msg ? $msg : "Parameter '$name' value cannot be empty");
        }
    }

    public static function arrayItemNotEmpty(array $array, string|int $key, string $msg = '') {
        if (!isset($array[$key]) || empty($array[$key])) {
            throw new TypeError($msg ? $msg : "Array must contain '$key'");
        }
    }

    public static function arrayItemNotSet(array $array, string|int $key, string $msg = '') {
        if (!isset($array[$key])) {
            throw new TypeError($msg ? $msg : "Array item '$key' must be set");
        }
    }

    public static function notNull(string $name, mixed $value, string $msg = '') {
        if (empty($value)) {
            throw new TypeError($msg ? $msg : "Parameter '$name' value cannot be Null");
        }
    }

    public static function state(bool $expression, string $message) {
        if (!$expression) {
            throw new IllegalStateException($message);
        }
    }
}