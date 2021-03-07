<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace test\winterframework\core\context;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\BeanProviderContext;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\type\StringSet;
use dev\winterframework\util\log\LoggerManager;
use Exception;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use test\winterframework\core\context\classes\t0001\T0001Service;
use test\winterframework\core\context\classes\t0001\T0002Service;
use test\winterframework\core\context\classes\t0001\T0003Service;
use test\winterframework\core\context\classes\t0001\T0004Service;
use test\winterframework\core\context\classes\t0001\T0005Service;
use test\winterframework\core\context\classes\t0001\T0006Service;
use test\winterframework\core\context\classes\t0001\T0021Service;
use test\winterframework\core\context\classes\t0001\T0022Service;
use test\winterframework\core\context\classes\t0001\T0023Service;
use test\winterframework\core\context\classes\t0001\T0024Service;
use test\winterframework\core\context\classes\t0001\T0030Service;
use test\winterframework\core\context\classes\t0001\T0031Service;
use test\winterframework\core\context\classes\t0001\T0032Service;
use test\winterframework\core\context\classes\t0001\T0032ServiceImpl;
use test\winterframework\lib\TestUtil;
use test\winterframework\TestApplication;

class WinterBeanProviderContextTests extends TestCase {
    private static ClassResourceScanner $scanner;
    private static ApplicationContextData $contextData;

    public static function setUpBeforeClass(): void {
        self::$scanner = ClassResourceScanner::getDefaultScanner();

        LoggerManager::getLogger()->pushHandler(new StreamHandler(STDOUT));

        $bootApp = self::$scanner->scanClass(
            TestApplication::class,
            StringSet::ofValues(WinterBootApplication::class)
        );
        /** @var WinterBootApplication $bootConfig */
        $bootConfig = $bootApp->getAttribute(WinterBootApplication::class);
        self::$contextData = new ApplicationContextData();
        self::$contextData->setBootApp($bootApp);
        self::$contextData->setBootConfig($bootConfig);
        self::$contextData->setPropertyContext(new WinterPropertyContext(
            $bootConfig->configDirectory,
            $bootConfig->profile
        ));
        self::$contextData->setScanner(self::$scanner);
    }

    private function noSuchBean(BeanProviderContext $provider, string $name = 'Xyz') {
        try {
            $provider->beanByName($name);
            $this->assertSame('There is no such bean exist with Xyz', '');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RuntimeException);
        }
    }

    private static function getApplicationContext(): array {
        return TestUtil::getApplicationContext();
    }

    private function beanNameExists(BeanProviderContext $provider, string $name) {
        try {
            $provider->beanByName($name);
            $this->assertSame($name . ' bean exist', $name . ' bean exist');
        } catch (Exception $e) {
            $this->assertSame('Bean with Name "' . $name . '" Does not exist', ''
                , $e->getMessage() . "\n");
        }
    }

    private function beanClassExists(BeanProviderContext $provider, string $className) {
        try {
            $provider->beanByClass($className);
            $this->assertSame($className . ' bean exist', $className . ' bean exist');
        } catch (Exception $e) {
            $this->assertSame('Bean with className "' . $className . '" Does not exist', ''
                , $e->getMessage() . "\n");
        }
    }

    public function testBeansLoad01(): void {
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $this->noSuchBean($provider);
    }

    public function testBeansLoad02(): void {
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0001Service::class));

        $this->noSuchBean($provider, 'Abc');
        $this->beanNameExists($provider, 'serviceX');
        $this->beanClassExists($provider, T0001Service::class);

        $this->assertSame($provider->beanByName('serviceX'), $provider->beanByClass(T0001Service::class));
        $this->assertInstanceOf(T0001Service::class, $provider->beanByName('serviceX'));
        /** @var T0001Service $bean */
        $bean = $provider->beanByName('serviceX');
        $this->assertSame($bean->getValue(), 10);
    }

    public function testBeansCyclicDependency03(): void {
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0002Service::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0003Service::class));

        $this->noSuchBean($provider, 'Abc');
        try {
            $this->beanClassExists($provider, T0002Service::class);
            $this->assertSame('There is no such bean exist with T0002Service', '');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RuntimeException);
        }

        /**
         * Self dependency
         */
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0004Service::class));

        $this->noSuchBean($provider, 'Abc');
        try {
            $this->beanClassExists($provider, T0004Service::class);
            $this->assertSame('There is no such bean exist with T0004Service', '');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RuntimeException);
        }

        /**
         * Dependency by ApplicationContext->getBean() calls
         */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();

        /** @var WinterBeanProviderContext $provider */
        $provider = TestUtil::getProperty($ctx, 'beanProvider');
        $provider->registerInternalBean($ctx, ApplicationContext::class);
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0005Service::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0006Service::class));

        $this->noSuchBean($provider, 'Abc');
        try {
            $this->beanClassExists($provider, T0005Service::class);
            $this->assertSame('cyclic dependency with T0005Service', '');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RuntimeException);
        }
    }

    public function testBeansMultiple04(): void {
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0021Service::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0022Service::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0023Service::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0024Service::class));

        $this->noSuchBean($provider, 'Abc');
        /** @var T0021Service $bean */
        $bean = $provider->beanByClass(T0021Service::class);
        $this->assertSame($bean->sum(), 100);
    }

    public function testBeansMultiple05(): void {
        /** @var $ctxData ApplicationContextData */
        /** @var $ctx WinterApplicationContext */
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($ctxData, $ctx) = self::getApplicationContext();
        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(T0030Service::class));

        $this->noSuchBean($provider, 'Abc');
        /** @var T0031Service $bean */
        $bean31 = $provider->beanByClass(T0031Service::class);
        $this->assertSame($bean31::class, T0031Service::class);
        $bean30 = $provider->beanByClass(T0030Service::class);
        $this->assertSame($bean30::class, T0030Service::class);

        $bean32 = $provider->beanByClass(T0032Service::class);
        $this->assertTrue($bean32 instanceof T0032Service);
        $this->assertSame($bean32::class, T0032ServiceImpl::class);
    }
}