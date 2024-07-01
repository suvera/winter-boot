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
use dev\winterframework\core\web\config\DefaultWebMvcConfigurer;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use dev\winterframework\io\metrics\prometheus\DefaultPrometheusMetricProvider;
use dev\winterframework\io\metrics\prometheus\KvAdapter;
use dev\winterframework\io\metrics\prometheus\NoAdapter;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\datasource\DataSourceBuilder;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\pdo\PdoTemplateProvider;
use dev\winterframework\ppa\EntityRegistry;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\Module;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\util\concurrent\DefaultLockManager;
use dev\winterframework\util\concurrent\LockManager;

abstract class WinterApplicationContextBuilder implements ApplicationContext {
    protected BeanProviderContext $beanProvider;
    protected ClassResources $resources;
    protected PropertyContext $propertyContext;
    protected WinterBootApplication $bootConfig;
    protected ClassResourceScanner $scanner;
    protected array $moduleRegistry = [];
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

    public function setProperty(string $name, mixed $value): mixed {
        return $this->propertyContext->set($name, $value);
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
            $this,
            ApplicationContext::class
        );

        $this->beanProvider->registerInternalBean(
            $this->contextData->getAopRegistry(),
            AopInterceptorRegistry::class
        );

        $this->beanProvider->registerInternalBean(
            new ApplicationLogger(),
            ApplicationLogger::class
        );

        $this->beanProvider->registerInternalBean(
            new DefaultResponseRenderer(),
            ResponseRenderer::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new DefaultErrorController(),
            ErrorController::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new DefaultWebMvcConfigurer(),
            WebMvcConfigurer::class,
            false
        );

        $cacheManager = new DefaultCacheManager();
        $this->beanProvider->registerInternalBean(
            $cacheManager,
            CacheManager::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleCacheResolver($cacheManager),
            CacheResolver::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleKeyGenerator(),
            KeyGenerator::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new SimpleKeyGenerator(),
            KeyGenerator::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new DefaultLockManager(),
            LockManager::class,
            false
        );

        $this->beanProvider->registerInternalBean(
            new IdleCheckRegistry(),
            IdleCheckRegistry::class,
            false
        );

        $this->registerPrometheusBeans();
    }

    private function registerDataSources(): void {
        if (!$this->propertyContext->has('datasource')) {
            return;
        }

        $ds = $this->propertyContext->get('datasource');
        if (!is_array($ds) || empty($ds)) {
            return;
        }

        $dsBuilder = new DataSourceBuilder($this, $this->contextData, $ds);
        foreach ($dsBuilder->getDataSourceConfig() as $beanName => $config) {
            if ($this->hasBeanByName($beanName)) {
                throw new NoUniqueBeanDefinitionException(
                    'DataSource creation failed, '
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

        if (
            !$this->hasBeanByClass(PdbcTemplate::class)
            && !$this->hasBeanByClass(PdoTemplateProvider::class)
        ) {
            $this->addClass(PdoTemplateProvider::class);
        }

        $this->beanProvider->registerInternalBeanMethod(
            '',
            PlatformTransactionManager::class,
            $dsBuilder,
            'getPrimaryTransactionManager',
            [],
            false
        );

        $this->beanProvider->registerInternalBeanMethod(
            $beanName . DataSourceBuilder::TXN_SUFFIX,
            '',
            $dsBuilder,
            'getTransactionManager',
            ['name' => $beanName . DataSourceBuilder::TXN_SUFFIX],
            false
        );

        $this->beanProvider->registerInternalBeanMethod(
            $beanName . DataSourceBuilder::TEMPLATE_SUFFIX,
            '',
            $dsBuilder,
            'getPdbcTemplate',
            ['name' => $beanName . DataSourceBuilder::TEMPLATE_SUFFIX],
            false
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
        foreach ($this->resources as $resource) {
            $this->processClassResource($resource);
        }
    }

    private function processClassResource(ClassResource $resource): void {
        $this->beanProvider->addProviderClass($resource);

        $table = $resource->getAttribute(Table::class);
        if ($table) {
            EntityRegistry::putEntity($resource);
        }
    }

    public function addClass(string $class): ClassResource {
        if ($this->resources->offsetGet($class) != null) {
            return $this->resources->offsetGet($class);
        }
        $clsResource = $this->scanner->scanClass(
            $class,
            $this->contextData->getAttributesToScan()
        );
        $this->resources[] = $clsResource;

        if (!$this->hasBeanByClass($class)) {
            $this->processClassResource($clsResource);
        }

        return $clsResource;
    }

    public function hasModule(string $moduleName): bool {
        return isset($this->moduleRegistry[$moduleName]);
    }

    public function addModule(string $moduleName, Module $module): void {
        $this->moduleRegistry[$moduleName] = $module;
    }

    public function getModule(string $moduleName): Module {
        if (!isset($this->moduleRegistry[$moduleName])) {
            throw new ModuleException('Could not find module ' . json_encode($moduleName));
        }
        return $this->moduleRegistry[$moduleName];
    }

    public function getModules(): array {
        return array_keys($this->moduleRegistry);
    }

    protected function registerPrometheusBeans(): void {

        $adapterBean = $this->getPropertyStr('winter.prometheus.bean', '');
        $adapterClass = $this->getPropertyStr('winter.prometheus.beanClass', '');
        $providerClass = $this->getPropertyStr(
            'winter.prometheus.metricProvider',
            DefaultPrometheusMetricProvider::class
        );

        if (!$adapterBean && !$adapterClass) {
            $port = $this->getPropertyInt('winter.kv.port', 0);;
            if ($port > 0) {
                $adapterClass = KvAdapter::class;
            } else {
                $adapterClass = NoAdapter::class;
            }
        }

        $this->contextData->getBeanProvider()->registerInternalBean(
            new PrometheusMetricRegistry(
                $this,
                $adapterBean,
                $adapterClass,
                $providerClass
            ),
            PrometheusMetricRegistry::class,
            false
        );
    }
}
