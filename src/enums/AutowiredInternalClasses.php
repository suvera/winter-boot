<?php
declare(strict_types=1);

namespace dev\winterframework\enums;

use dev\winterframework\core\context\ApplicationContext;

class AutowiredInternalClasses {
    private static array $CLASSES = [];

    public static function getAll(): array {

        if (empty(self::$CLASSES)) {

            self::$CLASSES = [
                ApplicationContext::class => ApplicationContext::class
            ];
        }
        return self::$CLASSES;
    }

    public static function contains(string $cls): bool {
        self::getAll();

        return isset(self::$CLASSES[$cls]);
    }
}