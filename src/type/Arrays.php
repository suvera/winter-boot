<?php
declare(strict_types=1);

namespace dev\winterframework\type;

final class Arrays {

    public static function flattenByKey(array $array, string $delimiter = '.', string $prefix = ''): array {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value))
                $result = array_merge(
                    $result,
                    self::flattenByKey($value, $delimiter, $prefix . $key . $delimiter)
                );
            else
                $result[$prefix . $key] = $value;
        }
        return $result;
    }
}