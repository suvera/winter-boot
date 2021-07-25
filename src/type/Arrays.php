<?php
declare(strict_types=1);

namespace dev\winterframework\type;

use TypeError;

final class Arrays {
    public static array $EMPTY_ARRAY = [];

    public static function flattenByKey(array $array, string $delimiter = '.', string $prefix = ''): array {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (is_int($key)) {
                    $curKey = substr($prefix, 0, 0 - strlen($delimiter));
                    if (!isset($result[$curKey])) {
                        $result[$curKey] = [];
                    }
                    $result[$curKey][$key] = self::flattenByKey($value, $delimiter, '');
                } else {
                    $result = array_merge(
                        $result,
                        self::flattenByKey($value, $delimiter, $prefix . $key . $delimiter)
                    );
                }

            } else {
                if (is_int($key)) {
                    $curKey = substr($prefix, 0, 0 - strlen($delimiter));
                    if (!isset($result[$curKey])) {
                        $result[$curKey] = [];
                    }
                    $result[$curKey][$key] = $value;
                } else {
                    $result[$prefix . $key] = $value;
                }
            }
        }
        return $result;
    }

    public static function assertKey(array $arr, string|int $key, string $msg): void {
        if (!isset($arr[$key])) {
            throw new TypeError("'$key' does not exist, " . $msg);
        }
    }
}