<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\MethodResource;

class AopInterceptorRegistry {

    private static array $registry = [];

    /**
     * AopInterceptorRegistry constructor.
     */
    private function __construct() {
    }

    private static AopInterceptorRegistry $instance;

    public static function getInstance(): AopInterceptorRegistry {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function register(ClassResource $class, MethodResource $method): void {
        self::$registry[$class->getClass()->getName()][$method->getMethod()->getShortName()]
            = new WinterAopInterceptor($class, $method);
    }

    public static function unregister(ClassResource $class, MethodResource $method): void {
        self::unregister2($class->getClass()->getName(), $method->getMethod()->getShortName());
    }

    public static function unregister2(string $className, string $methodName): void {
        if (isset(self::$registry[$className][$methodName])) {
            unset(self::$registry[$className][$methodName]);
        }
    }

    public static function get(string $className, string $methodName): AopInterceptor {
        if (isset(self::$registry[$className][$methodName])) {
            return self::$registry[$className][$methodName];
        }

        $resource = ClassResourceScanner::getDefaultScanner()->scanDefaultClass($className);

        if ($resource == null) {
            throw new ClassNotFoundException('Could not find Class ' . $className);
        }

        self::register($resource, $resource->getMethod($methodName));

        return self::$registry[$className][$methodName];
    }

}