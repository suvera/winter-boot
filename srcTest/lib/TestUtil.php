<?php
declare(strict_types=1);

namespace test\winterframework\lib;

use dev\winterframework\util\log\LoggerManager;
use Monolog\Handler\StreamHandler;
use ReflectionObject;
use test\winterframework\TestApplication;

class TestUtil {
    protected static bool $loggingEnabled = false;

    public static function getProperty(object $obj, string $property): mixed {
        $ref = new ReflectionObject($obj);
        /** @noinspection PhpUnhandledExceptionInspection */
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }

    public static function getApplicationContext(string $appClass = TestApplication::class): array {
        if (!self::$loggingEnabled) {
            LoggerManager::getLogger()->pushHandler(new StreamHandler(STDOUT));
            self::$loggingEnabled = true;
        }

        $winter = new WinterTestWebApplication();
        $winter->run($appClass);

        return [
            self::getProperty($winter, 'appCtxData'),
            self::getProperty($winter, 'applicationContext')
        ];
    }

}