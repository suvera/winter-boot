<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use JsonException;

class JsonUtil {

    /**
     * @param string|null $data
     * @return array|null
     * @throws
     */
    public static function decodeArray(?string $data): array|null {
        if (is_null($data)) {
            return null;
        }

        $data = trim($data);
        if ((substr($data, 0, 1) == '{' && substr($data, -1) == '}')
            || (substr($data, 0, 1) == '[' && substr($data, -1) == ']')
        ) {
            $output = json_decode($data, true, 128, JSON_THROW_ON_ERROR);

            if (gettype($output) === 'array') {
                return $output;
            }
        }

        throw new JsonException('Invalid JSON string');
    }

    /**
     * @param mixed $data
     * @return string|null
     */
    public static function encode(mixed $data): ?string {
        return json_encode($data);
    }
}