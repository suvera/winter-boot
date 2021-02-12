<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace test\winterframework\cache;

use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\util\log\LoggerManager;
use Monolog\Handler\StreamHandler;
use Monolog\Test\TestCase;
use test\winterframework\cache\classes\Cache001;
use test\winterframework\lib\TestUtil;
use test\winterframework\TestCachedApplication;

class CacheableTests extends TestCase {
    private static ClassResourceScanner $scanner;

    public static function setUpBeforeClass(): void {
        self::$scanner = ClassResourceScanner::getDefaultScanner();
        LoggerManager::getLogger()->pushHandler(new StreamHandler(STDOUT));
    }

    public function testCacheable01() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestCachedApplication::class);

        $defaultAttrs = self::$scanner->getDefaultStereoTypes();
        $defaultAttrs[] = Cacheable::class;

        $provider = $ctxData->getBeanProvider();
        $provider->addProviderClass(
            self::$scanner->scanClass(Cache001::class, $defaultAttrs)
        );

        /** @var Cache001 $bean */
        $bean = $appCtx->beanByClass(Cache001::class);
        $this->assertTrue(is_a($bean::class, Cache001::class, true));
        echo "\n" . get_class($bean) . "\n";

        $this->assertSame(11, $bean->noCacheTest());
        $this->assertSame(12, $bean->noCacheTest());
        $this->assertSame(13, $bean->noCacheTest());

        $this->assertSame(11, $bean->cachedTest());
        $this->assertSame(11, $bean->cachedTest());
        $this->assertSame(11, $bean->cachedTest());
    }

}