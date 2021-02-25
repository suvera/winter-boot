<?php
declare(strict_types=1);

namespace test\winterframework\pdbc;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\util\log\Wlf4p;
use PHPUnit\Framework\TestCase;
use test\winterframework\lib\TestUtil;
use test\winterframework\pdbc\classess\TestPdbc001;
use test\winterframework\pdbc\classess\TestPdbc002;
use test\winterframework\TestPdbcApplication;

class PdbcTemplateTests extends TestCase {
    use Wlf4p;

    public function testPdbcTemplate001() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestPdbcApplication::class);

        $appCtx->addClass(TestPdbc001::class);

        /** @var TestPdbc001 $bean */
        $bean = $appCtx->beanByClass(TestPdbc001::class);

        $this->assertSame($bean->templateTest01(), 'Hello, Suvera');

        $row = $bean->templateTest02();
        $this->assertSame($row['COLUMN1'], 'Hello, Suvera');
    }

    public function testPdbcTemplate002() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestPdbcApplication::class);

        $appCtx->addClass(TestPdbc002::class);

        echo "\n";
        /** @var TestPdbc002 $bean */
        $bean = $appCtx->beanByClass(TestPdbc002::class);

        $ret = $bean->transactionTest01();
        $this->assertSame(true, $ret);

        $rows = $bean->transactionTest02();
        $this->assertSame(1, count($rows));
        $this->assertSame('Suvera', $rows[0]['name']);

        $ret = $bean->transactionTest01();
        $this->assertSame(true, $ret);

        $ret = $bean->transactionTest01();
        $this->assertSame(true, $ret);

        $rows = $bean->transactionTest02();
        //print_r($rows);
        $this->assertSame(3, count($rows));
        $this->assertSame('Suvera', $rows[0]['name']);
        $this->assertSame('Suvera', $rows[2]['name']);

    }
}