<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\CacheResolver;
use dev\winterframework\cache\impl\DefaultCacheManager;
use dev\winterframework\cache\impl\SimpleCacheResolver;
use dev\winterframework\cache\impl\SimpleKeyGenerator;
use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;

abstract class WinterApplicationContextBuilder implements ApplicationContext {
    protected BeanProviderContext $beanProvider;
    protected ClassResources $resources;
    protected PropertyContext $propertyContext;
    protected WinterBootApplication $bootConfig;
    protected ClassResourceScanner $scanner;
    private bool $built = false;
    protected int $startTime;

    public function __construct(
        protected ApplicationContextData $contextData
    ) {
        $this->startTime = intval(microtime(true) * 1000);

        $this->scanner = $this->contextData->getScanner();
        $this->resources = $this->contextData->getResources();
        $this->bootConfig = $this->contextData->getBootConfig();
        $this->propertyContext = $this->contextData->getPropertyContext();
        $this->contextData->setPropertyContext($this->propertyContext);
        $this->contextData->setAopRegistry(new AopInterceptorRegistry($this->contextData, $this));

        $this->beanProvider = new WinterBeanProviderContext($this->contextData, $this);
        $this->contextData->setBeanProvider($this->beanProvider);
    }

    /**
     * Public METHODS
     * -------------------------------------------------
     *
     * @param string $name
     * @return object|null
     */
    public function beanByName(string $name): ?object {
        return $this->beanProvider->beanByName($name);
    }

    public function beanByClass(string $class): ?object {
        return $this->beanProvider->beanByClass($class);
    }

    public function beanByNameClass(string $name, string $class): ?object {
        return $this->beanProvider->beanByNameClass($name, $class);
    }

    public function hasBeanByName(string $name): bool {
        return $this->beanProvider->hasBeanByName($name);
    }

    public function hasBeanByClass(string $class): bool {
        return $this->beanProvider->hasBeanByClass($class);
    }

    /**
     * LOCAL Methods
     * ---------------------------------
     */
    public function buildContext(): void {
        if ($this->built) {
            return;
        }
        $this->processResources();

        $this->registerInternals();

        if ($this->bootConfig->eager) {
            $this->eagerLoadBeans();
        }
    }

    private function registerInternals(): void {
        $this->beanProvider->registerInternalBean(
            $this, ApplicationContext::class
        );

        $this->beanProvider->registerInternalBean(
            $this->contextData->getAopRegistry(), AopInterceptorRegistry::class
        );

        $this->beanProvider->registerInternalBean(
            new ApplicationLogger(), ApplicationLogger::class
        );

        $this->beanProvider->registerInternalBean(
            new DefaultResponseRenderer(), ResponseRenderer::class, false
        );

        $this->beanProvider->registerInternalBean(
            new DefaultErrorController(), ErrorController::class, false
        );

        $cacheManager = new DefaultCacheManager();
        $this->beanProvider->registerInternalBean(
            $cacheManager, CacheManager::class, false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleCacheResolver($cacheManager), CacheResolver::class, false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleKeyGenerator(), KeyGenerator::class, false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleKeyGenerator(), KeyGenerator::class, false
        );

    }

    private function eagerLoadBeans(): void {
        foreach ($this->beanProvider->getBeanClassFactory() as $beanClass => $list) {
            foreach ($list as $beanSubClass => $beanProvider) {
                if ($beanSubClass === $beanClass) {
                    $this->beanProvider->beanByClass($beanClass);
                }
            }
        }
    }


    private function processResources(): void {
        $this->processClassResource($this->contextData->getBootApp());

        foreach ($this->resources as $resource) {
            $this->processClassResource($resource);
        }
    }

    private function processClassResource(ClassResource $resource): void {
        $this->beanProvider->addProviderClass($resource);
    }

}