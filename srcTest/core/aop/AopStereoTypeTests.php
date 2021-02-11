<?php
declare(strict_types=1);

namespace test\winterframework\core\aop;

use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\util\log\LoggerManager;
use Monolog\Handler\StreamHandler;
use Monolog\Test\TestCase;
use test\winterframework\core\aop\classes\A001Class;

class AopStereoTypeTests extends TestCase {

    private static ClassResourceScanner $scanner;

    public static function setUpBeforeClass(): void {
        self::$scanner = ClassResourceScanner::getDefaultScanner();
        LoggerManager::getLogger()->pushHandler(new StreamHandler(STDOUT));
    }

    public function testAopStereoType01() {
        $provider = new WinterBeanProviderContext();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(A001Class::class));

        /** @var A001Class $bean */
        $bean = $provider->beanByClass(A001Class::class);

        $bean->syncMethod(1);
        $this->assertTrue(true);
    }

}