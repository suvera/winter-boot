<?php

declare(strict_types=1);

namespace dev\winterframework\core\app;

use Cascade\Cascade;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\core\context\ShutDownRegistry;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\exception\NotWinterApplicationException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\Psr4Namespace;
use dev\winterframework\reflection\Psr4Namespaces;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\cache\EnableCaching;
use dev\winterframework\stereotype\Module;
use dev\winterframework\stereotype\OnApplicationReady;
use dev\winterframework\stereotype\task\EnableAsync;
use dev\winterframework\stereotype\task\EnableScheduling;
use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\StringSet;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\LoggerManager;
use dev\winterframework\util\log\WinterConsoleLogFormatter;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\PropertyLoader;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessIdProcessor;

abstract class WinterApplicationRunner {
    use Wlf4p;

    protected WinterApplicationContext $applicationContext;
    protected ClassResource $bootApp;
    protected WinterBootApplication $bootConfig;
    protected ApplicationContextData $appCtxData;
    protected ClassResourceScanner $scanner;
    protected ClassResources $resources;
    protected Psr4Namespaces $scanNamespaces;
    protected PropertyContext $propertyCtx;
    protected StringSet $attributesToScan;
    protected Logger $console;
    protected string $configDir = '';
    protected WinterCliArguments $args;

    public function __construct() {
        global $argv;
        $this->scanner = ClassResourceScanner::getDefaultScanner();

        $this->args = new WinterCliArguments();
        $configDir = $this->args->getConfigDir();
        if ($configDir) {
            $this->configDir = $configDir;
        }

        // Initialize with a basic logger in case buildApplicationLogger doesn't run
        $this->console = new Logger('winter_console_logger');
        $this->console->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
    }

    public function getBootVersion(): string {
        return '1.0.0-Dev';
    }

    public final function run(string $appClass): void {
        $this->bootApp = $this->buildBootApp($appClass);
        $this->processBootConfig();

        $this->propertyCtx = new WinterPropertyContext(
            $this->bootConfig->configDirectory,
            $this->bootConfig->profile
        );

        $this->buildApplicationLogger(
            $this->bootConfig->configDirectory,
            $this->bootConfig->profile
        );
        self::logInfo('Starting Application ' . $this->bootApp->getClass()->getShortName());
        $this->showBanner();

        $this->scanAppNamespaces();

        $this->appCtxData = $this->buildApplicationContextData();
        $this->applicationContext = new WinterApplicationContext($this->appCtxData, $this->args);

        $this->buildAppContext();

        $this->startBootApp();
    }

    protected function buildBootApp(string $appClass): ClassResource {
        $resource = $this->scanner->scanClass(
            $appClass,
            StringSet::ofValues(
                WinterBootApplication::class,
                EnableCaching::class,
                EnableTransactionManagement::class,
                EnableAsync::class,
                EnableScheduling::class
            )
        );

        if ($resource == null) {
            throw new NotWinterApplicationException(
                "Could not find WinterBootApplication for class '$appClass'"
            );
        }
        return $resource;
    }

    protected function startBootApp(): void {
        $appClass = $this->bootApp->getClass()->getName();
        $this->applicationContext->addClass($appClass);
        $this->applicationContext->beanByClass($appClass);

        $this->loadModules();

        $this->runBootApp();
    }

    protected function buildAppContext(): void {
        $this->applicationContext->buildContext();
    }

    private function buildApplicationContextData(): ApplicationContextData {

        $data = new ApplicationContextData();
        $data->setScanner($this->scanner);
        $data->setBootApp($this->bootApp);
        $data->setBootConfig($this->bootConfig);
        $data->setResources($this->resources);
        $data->setPropertyContext($this->propertyCtx);
        $data->setAttributesToScan($this->attributesToScan);
        $data->setShutDownRegistry(new ShutDownRegistry());
        $data->setInterceptorRegistry(new InterceptorRegistry());

        return $data;
    }

    private function processBootConfig(): void {
        /** @var WinterBootApplication $bootConfig */
        $bootConfig = $this->bootApp->getAttribute(WinterBootApplication::class);
        if (!empty($this->configDir)) {
            $bootConfig->configDirectory = [$this->configDir];
        }
        if (empty($bootConfig->configDirectory)) {
            throw new WinterException('configDirectory is empty for application '
                . ReflectionUtil::getFqName($this->bootApp));
        }
        TypeAssert::stringArray(
            $bootConfig->configDirectory,
            ' configDirectory is not configured well, please follow documentation'
        );

        if (empty($bootConfig->scanNamespaces)) {
            throw new WinterException('scanNamespaces is empty for application '
                . ReflectionUtil::getFqName($this->bootApp));
        }

        $this->scanNamespaces = Psr4Namespaces::ofValues();
        foreach ($bootConfig->scanNamespaces as $nsRow) {
            TypeAssert::array(
                $nsRow,
                ' scanNamespaces is not configured well, please follow documentation'
            );
            TypeAssert::string(
                $nsRow[0],
                ' scanNamespaces is not configured well, please follow documentation'
            );
            TypeAssert::string(
                $nsRow[1],
                ' scanNamespaces is not configured well, please follow documentation'
            );

            $this->scanNamespaces[] = new Psr4Namespace($nsRow[0], $nsRow[1]);
        }

        TypeAssert::stringArray(
            $bootConfig->scanExcludeNamespaces,
            ' scanExcludeNamespaces is not configured well, please follow documentation'
        );

        $this->bootConfig = $bootConfig;
    }

    private function scanAppNamespaces(): void {
        if (!isset($this->resources)) {
            $this->initModules();

            $this->findAttributesToScan();

            $this->resources = $this->scanner->scan(
                $this->nameSpacesToScan($this->scanNamespaces),
                $this->attributesToScan,
                $this->bootConfig->autoload,
                $this->bootConfig->scanExcludeNamespaces
            );
        }

        //print_r($this->resources);
    }

    private function nameSpacesToScan(Psr4Namespaces $ns): Psr4Namespaces {
        return $ns;
    }

    private function findAttributesToScan(): void {
        $this->attributesToScan = $this->scanner->getDefaultStereoTypes();

        if ($this->bootApp->getAttribute(EnableCaching::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/cache/stereotype',
                    'dev\\winterframework\\cache\\stereotype'
                )
            );
            $this->attributesToScan->addAll($cacheTypes);
        }

        if ($this->bootApp->getAttribute(EnableTransactionManagement::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/txn/stereotype',
                    'dev\\winterframework\\txn\\stereotype'
                )
            );
            $this->attributesToScan->addAll($cacheTypes);
        }

        if ($this->bootApp->getAttribute(EnableAsync::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/task/async/stereotype',
                    'dev\\winterframework\\task\\async\\stereotype'
                )
            );
            $this->attributesToScan->addAll($cacheTypes);
        }

        if ($this->bootApp->getAttribute(EnableScheduling::class) != null) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/task/scheduling/stereotype',
                    'dev\\winterframework\\task\\scheduling\\stereotype'
                )
            );
            $this->attributesToScan->addAll($cacheTypes);
        }

        $propCtx = $this->propertyCtx;
        if ($propCtx->getBool('management.endpoints.enabled', false)) {
            $cacheTypes = array_keys(
                DirectoryScanner::scanForPhpClasses(
                    dirname(dirname(__DIR__)) . '/actuator/stereotype',
                    'dev\\winterframework\\actuator\\stereotype'
                )
            );
            $this->attributesToScan->addAll($cacheTypes);
        }
    }

    protected function loadModules() {
        $modules = $this->propertyCtx->get('modules', []);
        foreach ($modules as $moduleDef) {
            if (!$moduleDef['module'] || !$moduleDef['enabled']) {
                continue;
            }
            $clsRef = $this->applicationContext->addClass($moduleDef['module']);

            /** @var Module $module */
            $module = $clsRef->getAttribute(Module::class);

            if ($module) {
                $module->setConfig($moduleDef);

                $obj = $this->applicationContext->beanByClass($module->getClassName());

                $this->applicationContext->addModule($module->getClassName(), $module);

                if ($module->initMethod) {
                    $obj->{$module->initMethod}($this->applicationContext, $this->appCtxData);
                }

                self::logInfo("Module [ $module->title ] loaded. ");
            }
        }
    }

    protected function initModules() {
        $modules = $this->propertyCtx->get('modules', []);
        foreach ($modules as $moduleDef) {
            if (!$moduleDef['module'] || !$moduleDef['enabled']) {
                continue;
            }

            $clsRef = RefKlass::getInstance($moduleDef['module']);

            /** @var Module $module */
            $attrs = $clsRef->getAttributes(Module::class);

            if ($attrs) {
                $module = ReflectionUtil::createAttribute($attrs[0], $clsRef);

                self::logInfo("Module [ $module->title ] loading.");

                foreach ($module->namespaces as $nsRow) {
                    $this->scanNamespaces[] = new Psr4Namespace($nsRow[0], $nsRow[1]);
                }
            }
        }
    }

    private function buildApplicationLogger(array $configDirs, ?string $profile = null): void {
        $suffix = (isset($profile) && strlen($profile) ? '-' . $profile : '');
        $logFiles = [
            'logger' . $suffix . '.yml',
            'logger' . '.yml',
        ];

        $data = null;
        foreach ($logFiles as $logFile) {
            $configFiles = DirectoryScanner::scanFileInDirectories($configDirs, $logFile);

            foreach ($configFiles as $configFile) {
                $data = PropertyLoader::loadLogging($configFile);
                break 2;
            }
        }

        if (empty($data)) {
            // Initialize with default console logger when no config is found
            $defaultData = [
                'loggers' => [
                    'winter_console_logger' => [
                        'handlers' => ['winter_consoled'],
                        'processors' => ['winter_pid_processor']
                    ]
                ],
                'formatters' => [
                    'winter_console_formatter' => [
                        'class' => WinterConsoleLogFormatter::class,
                        'format' => "%datetime% [%extra.process_id%] [%level_name%] - %message%\n"
                    ]
                ],
                'handlers' => [
                    'winter_consoled' => [
                        'class' => StreamHandler::class,
                        'level' => 'INFO',
                        'formatter' => 'winter_console_formatter',
                        'processors' => ['winter_pid_processor'],
                        'stream' => 'php://stdout'
                    ]
                ],
                'processors' => [
                    'winter_pid_processor' => [
                        'class' => ProcessIdProcessor::class
                    ]
                ]
            ];

            LoggerManager::buildInstance($defaultData);
            $this->console = Cascade::getLogger('winter_console_logger');
            return;
        }

        $data['loggers']['winter_console_logger'] = [
            'handlers' => ['winter_consoled'],
            'processors' => ['winter_pid_processor']
        ];
        $data['formatters']['winter_console_formatter'] = [
            'class' => WinterConsoleLogFormatter::class,
            'format' => "%datetime% [%extra.process_id%] [%level_name%] - %message%\n"
        ];
        $data['handlers']['winter_consoled'] = [
            'class' => StreamHandler::class,
            'level' => 'INFO',
            'formatter' => 'winter_console_formatter',
            'processors' => ['winter_pid_processor'],
            'stream' => 'php://stdout'
        ];
        $data['processors']['winter_pid_processor'] = [
            'class' => ProcessIdProcessor::class
        ];

        LoggerManager::buildInstance($data);
        $this->console = Cascade::getLogger('winter_console_logger');
    }

    protected function beginModules(): void {
        $appCtx = $this->applicationContext;

        foreach ($appCtx->getModules() as $moduleName) {
            $module = $appCtx->beanByClass($moduleName);

            if ($module instanceof WinterModule) {
                $module->begin($appCtx, $this->appCtxData);
            }
        }
    }

    protected function onApplicationReady(): void {
        $readyEvents = $this->resources->getClassesByAttribute(OnApplicationReady::class);
        /** @var ClassResource[] $resources */
        $resources = [];
        foreach ($readyEvents as $clsRes) {
            /** @var ClassResource $clsRes */
            $resources[$clsRes->getClass()->getName()] = $clsRes;
        }

        foreach ($resources as $resource) {
            /** @var ApplicationReadyEvent $bean */
            $bean = $this->applicationContext->beanByClass($resource->getClass()->getName());
            $bean->onApplicationReady();
        }

        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $this->applicationContext->beanByClass(IdleCheckRegistry::class);
        $idleCheck->initialize();
    }

    /**
     * Show the application banner with relevant information. The banner can be customized by providing a 
     * banner file in the configuration directory.
     */
    protected function showBanner(): void {
        $bannerFile = $this->propertyCtx->getStr('banner.location', '');

        if ($bannerFile && is_file($bannerFile)) {
            $bannerText = file_get_contents($bannerFile);
        } else {
            $bannerText = <<<EOQ
  _      _______  _______________      ___  ____  ____  ______
 | | /| / /  _/ |/ /_  __/ __/ _ \    / _ )/ __ \/ __ \/_  __/
 | |/ |/ // //    / / / / _// , _/   / _  / /_/ / /_/ / / /   
 |__/|__/___/_/|_/ /_/ /___/_/|_|   /____/\____/\____/ /_/    

\${winterBoot.name}: \${winterBoot.version}
\${app.name}: \${app.version}
\${php.name}: \${php.version}
\${swoole.name}: \${swoole.version}
\${rdkafka.name}: \${rdkafka.version}
\${redis.name}: \${redis.version}
EOQ;
        }

        $appName = $GLOBALS['winter.application.name'] ?? $this->propertyCtx->getStr('winter.application.name', '');
        $appVersion = $GLOBALS['winter.application.version'] ?? $this->propertyCtx->getStr('winter.application.version', '');

        $labels = [
            '${winterBoot.name}' => 'Winter Boot',
            '${winterBoot.version}' => $this->getBootVersion(),
            '${app.name}' => $appName,
            '${app.version}' => $appVersion,
            '${php.name}' => 'PHP',
            '${php.version}' => phpversion() . ', ' . php_sapi_name(),
        ];
        $extensions = ['swoole', 'rdkafka', 'redis'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $labels['${' . $ext . '.name}'] = ucwords($ext);
                $labels['${' . $ext . '.version}'] = phpversion($ext);
            }
        }

        $bannerText = str_replace(array_keys($labels), array_values($labels), $bannerText);
        $bannerText = preg_replace('/[\s:]+$/', '', $bannerText);

        $this->console->info("\n" . $bannerText);
    }

    /**
     * Exit the application with the specified status.
     *
     * @param int $status The exit status.
     * @return void
     */
    public function exit(int $status = 0): void {
        /**
         * Exit after migrations complete.
         *
         * The Swoole IdleCheckRegistry timer event loop is started during datasource building
         * (DataSourceBuilder::buildDataSource calls IdleCheckRegistry::register which calls Timer::tick).
         * This creates a non-blocking event loop that keeps PHP running even after the main logic completes.
         * We need explicit exit() to terminate the process.
         */
        IdleCheckRegistry::clearAll();
        exit($status);
    }

    /**
     * Run the boot application logic. This method should be implemented by subclasses to define the specific 
     * behavior of the boot application.
     */
    protected abstract function runBootApp(): void;

}
