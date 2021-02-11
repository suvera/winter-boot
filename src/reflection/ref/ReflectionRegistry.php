<?php
declare(strict_types=1);

namespace dev\winterframework\reflection\ref;

use dev\winterframework\exception\WinterException;
use ReflectionClass;
use ReflectionException;

class ReflectionRegistry {
    /**
     * @var ReflectionClass[]
     */
    private static array $classes = [];

    public static function getClass(string $className): ReflectionClass {

        if (isset(self::$classes[$className])) {
            return self::$classes[$className];
        }
        try {
            return self::$classes[$className] = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new WinterException('Could not load/find class '
                . $className, 0, $e
            );
        }
    }
}