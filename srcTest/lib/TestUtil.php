<?php
declare(strict_types=1);

namespace test\winterframework\lib;

use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\CacheResolver;
use dev\winterframework\cache\impl\DefaultCacheManager;
use dev\winterframework\cache\impl\SimpleCacheResolver;
use dev\winterframework\cache\impl\SimpleKeyGenerator;
use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\ApplicationLogger;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\StringList;
use ReflectionObject;
use test\winterframework\TestApplication;

class TestUtil {

    public static function getProperty(object $obj, string $property): mixed {
        $ref = new ReflectionObject($obj);
        /** @noinspection PhpUnhandledExceptionInspection */
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }

    public static function getApplicationContext(string $appClass = TestApplication::class): array {
        $scanner = ClassResourceScanner::getDefaultScanner();
        $bootApp = $scanner->scanClass(
            TestApplication::class,
            StringList::ofValues(WinterBootApplication::class)
        );
        /** @var WinterBootApplication $bootConfig */
        $bootConfig = $bootApp->getAttribute(WinterBootApplication::class);
        $contextData = new ApplicationContextData();
        $contextData->setBootApp($bootApp);
        $contextData->setBootConfig($bootConfig);
        $contextData->setScanner($scanner);
        $contextData->setPropertyContext(new WinterPropertyContext(
            $bootConfig->configDirectory,
            $bootConfig->profile
        ));
        $contextData->setResources(ClassResources::ofValues());

        $appCtx = new WinterApplicationContext($contextData);
        $contextData->getBeanProvider()->registerInternalBean(
            $appCtx, ApplicationContext::class
        );

        $contextData->getBeanProvider()->registerInternalBean(
            $contextData->getAopRegistry(), AopInterceptorRegistry::class
        );

        $contextData->getBeanProvider()->registerInternalBean(
            new ApplicationLogger(), ApplicationLogger::class
        );

        $contextData->getBeanProvider()->registerInternalBean(
            new DefaultResponseRenderer(), ResponseRenderer::class, false
        );

        $contextData->getBeanProvider()->registerInternalBean(
            new DefaultErrorController(), ErrorController::class, false
        );

        $cacheManager = new DefaultCacheManager();
        $contextData->getBeanProvider()->registerInternalBean(
            $cacheManager, CacheManager::class, false
        );

        $contextData->getBeanProvider()->registerInternalBean(
            new SimpleCacheResolver($cacheManager), CacheResolver::class, false
        );

        $contextData->getBeanProvider()->registerInternalBean(
            new SimpleKeyGenerator(), KeyGenerator::class, false
        );

        return [$contextData, $appCtx];
    }

}