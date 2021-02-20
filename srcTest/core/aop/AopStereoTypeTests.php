<?php
declare(strict_types=1);

namespace test\winterframework\core\aop;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\util\log\LoggerManager;
use Monolog\Handler\StreamHandler;
use Monolog\Test\TestCase;
use test\winterframework\core\aop\classes\A001Class;
use test\winterframework\lib\TestUtil;

class AopStereoTypeTests extends TestCase {

    private static ClassResourceScanner $scanner;

    public static function setUpBeforeClass(): void {
        self::$scanner = ClassResourceScanner::getDefaultScanner();
    }

    public function testAopStereoType01() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext();

        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(self::$scanner->scanDefaultClass(A001Class::class));

        /** @var A001Class $bean */
        $bean = $provider->beanByClass(A001Class::class);

        $bean->syncMethod(1);
        $this->assertTrue(true);

        $bean->syncIdMethod(1001);
        $bean->syncIdNameMethod(1002, 'Duke Wayne');
        $this->assertTrue(true);

        $bean->syncGetterMethod();
    }

}