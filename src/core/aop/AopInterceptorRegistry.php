<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\MethodResource;

class AopInterceptorRegistry {

    private array $registry = [];

    public function __construct(
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
    }

    public function register(ClassResource $class, MethodResource $method): void {
        $this->registry[$class->getClass()->getName()][$method->getMethod()->getShortName()]
            = new WinterAopInterceptor($class, $method, $this->ctxData, $this->appCtx);
    }

    public function unregister(ClassResource $class, MethodResource $method): void {
        self::unregister2($class->getClass()->getName(), $method->getMethod()->getShortName());
    }

    public function unregister2(string $className, string $methodName): void {
        if (isset($this->registry[$className][$methodName])) {
            unset($this->registry[$className][$methodName]);
        }
    }

    public function get(string $className, string $methodName): AopInterceptor {
        if (isset($this->registry[$className][$methodName])) {
            return $this->registry[$className][$methodName];
        }

        $resource = ClassResourceScanner::getDefaultScanner()->scanDefaultClass($className);

        if ($resource == null) {
            throw new ClassNotFoundException('Could not find Class ' . $className);
        }

        $this->register($resource, $resource->getMethod($methodName));

        return $this->registry[$className][$methodName];
    }

}