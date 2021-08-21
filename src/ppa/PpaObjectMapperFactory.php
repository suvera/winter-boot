<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

class PpaObjectMapperFactory {
    /**
     * @var PpaObjectMapper[]
     */
    protected static array $mappers = [];

    public static function getMapper(string $driverType): PpaObjectMapper {
        if (!isset(self::$mappers[$driverType])) {
            self::$mappers[$driverType] = new PpaGenericObjectMapper();
        }
        return self::$mappers[$driverType];
    }
}