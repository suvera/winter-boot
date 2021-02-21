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
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\datasource\DataSourceBuilder;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\pdo\PdoTemplateProvider;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\util\concurrent\DefaultLockManager;
use dev\winterframework\util\concurrent\LockManager;

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

    public function getProperty(string $name, mixed $default = null): string|int|float|bool|null {
        return $this->propertyContext->get($name, $default);
    }

    public function getPropertyStr(string $name, string $default = null): string {
        return $this->propertyContext->getStr($name, $default);
    }

    public function getPropertyBool(string $name, bool $default = null): bool {
        return $this->propertyContext->getBool($name, $default);
    }

    public function getPropertyInt(string $name, int $default = null): int {
        return $this->propertyContext->getInt($name, $default);
    }

    public function getPropertyFloat(string $name, float $default = null): float {
        return $this->propertyContext->getFloat($name, $default);
    }

    public function getProperties(): array {
        return $this->propertyContext->getAll();
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

        $this->registerDataSources();

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

        $this->beanProvider->registerInternalBean(
            new DefaultLockManager(), LockManager::class, false
        );

    }

    private function registerDataSources(): void {
        if (!$this->propertyContext->has('datasource')) {
            return;
        }

        $ds = $this->propertyContext->get('datasource');
        if (!is_array($ds) || empty($ds)) {
            return;
        }

        $dsBuilder = new DataSourceBuilder($ds);
        foreach ($dsBuilder->getDataSourceConfig() as $beanName => $config) {
            if ($this->hasBeanByName($beanName)) {
                throw new NoUniqueBeanDefinitionException('DataSource creation failed, '
                    . 'due to no qualifying bean with name '
                    . "'$beanName' available: expected single matching bean but found multiple "
                    . DataSource::class
                );
            }

            $this->beanProvider->registerInternalBeanMethod(
                $beanName,
                $config->isPrimary() ? DataSource::class : '',
                $dsBuilder,
                $config->isPrimary() ? 'getPrimaryDataSource' : 'getDataSource',
                $config->isPrimary() ? [] : ['name' => $beanName],
                false
            );
        }

        if (!$this->hasBeanByClass(PdbcTemplate::class)
            && !$this->hasBeanByClass(PdoTemplateProvider::class)) {
            $this->addClass(PdoTemplateProvider::class);
        }

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

    public function addClass(string $class): bool {
        if ($this->hasBeanByClass($class)) {
            return false;
        }
        $this->processClassResource(
            $this->scanner->scanClass(
                $class,
                $this->contextData->getAttributesToScan()
            )
        );
        return true;
    }

}