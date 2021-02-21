<?php
declare(strict_types=1);

namespace test\winterframework\pdbc;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\util\log\Wlf4p;
use PHPUnit\Framework\TestCase;
use test\winterframework\lib\TestUtil;
use test\winterframework\pdbc\classess\TestPdbc001;
use test\winterframework\TestCachedApplication;

class PdbcTemplateTests extends TestCase {
    use Wlf4p;

    public function testPdbcTemplate001() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestCachedApplication::class);

        $appCtx->addClass(TestPdbc001::class);

        /** @var TestPdbc001 $bean */
        $bean = $appCtx->beanByClass(TestPdbc001::class);

        $this->assertSame($bean->templateTest01(), 'Hello, Suvera');

        $row = $bean->templateTest02();
        $this->assertSame($row['COLUMN1'], 'Hello, Suvera');
    }
}