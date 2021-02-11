<?php
declare(strict_types=1);

namespace test\winterframework\lib;

use ReflectionObject;

class TestUtil {
    public static function getProperty(object $obj, string $property): mixed {
        $ref = new ReflectionObject($obj);
        /** @noinspection PhpUnhandledExceptionInspection */
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }
}