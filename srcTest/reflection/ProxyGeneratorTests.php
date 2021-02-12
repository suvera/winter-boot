<?php
declare(strict_types=1);

namespace test\winterframework\reflection;

use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\proxy\ProxyGenerator;
use dev\winterframework\util\log\LoggerManager;
use Exception;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use test\winterframework\core\context\classes\t0002\ProxyTest001;
use test\winterframework\core\context\classes\t0002\ProxyTest002;
use test\winterframework\lib\TestUtil;

class ProxyGeneratorTests extends TestCase {

    private static ClassResourceScanner $scanner;

    public static function setUpBeforeClass(): void {
        self::$scanner = ClassResourceScanner::getDefaultScanner();
        LoggerManager::getLogger()->pushHandler(new StreamHandler(STDOUT));
    }

    private function beanClassExists(WinterBeanProviderContext $provider, string $className) {
        try {
            $provider->beanByClass($className);
            $this->assertSame($className . ' bean exist', $className . ' bean exist');
        } catch (Exception $e) {
            $this->assertSame('Bean with className "' . $className . '" Does not exist', ''
                , $e->getMessage() . "\n");
        }
    }

    public function testProxyGenerator01() {
        $code = ProxyGenerator::getDefault()->generateClass(
            self::$scanner->scanDefaultClass(ProxyTest001::class)
        );

        $className = ProxyGenerator::getProxyClassName(ProxyTest001::class);

        echo "\n" . __METHOD__ . "\n";
        //echo $code;
        $this->assertNotEmpty($code);

        eval($code);

        $this->assertTrue(class_exists($className), "Class $className should exist!");
    }

    public function testProxyGenerator02() {
        /** @var ApplicationContextData $ctxData */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext();

        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(ProxyTest002::class));
        $provider->addProviderClass(self::$scanner->scanDefaultClass(ProxyTest001::class));

        $this->beanClassExists($provider, ProxyTest002::class);
        $this->beanClassExists($provider, ProxyTest001::class);

        /** @var ProxyTest001 $bean */
        $bean = $provider->beanByClass(ProxyTest001::class);
        $this->assertTrue(is_a($bean::class, ProxyTest001::class, true));

        /** @noinspection PhpExpressionResultUnusedInspection */
        $bean->synchronizedMethod();

    }
}