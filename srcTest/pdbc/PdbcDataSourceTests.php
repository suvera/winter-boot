<?php
declare(strict_types=1);

namespace test\winterframework\pdbc;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\pdbc\DataSource;
use PHPUnit\Framework\TestCase;
use test\winterframework\lib\TestUtil;
use test\winterframework\TestCachedApplication;

class PdbcDataSourceTests extends TestCase {

    public function testDataSource001() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestCachedApplication::class);

        /** @var DataSource $bean */
        $bean = $appCtx->beanByClass(DataSource::class);

        $this->assertTrue($bean instanceof DataSource);

        $bean->getConnection();
    }
}