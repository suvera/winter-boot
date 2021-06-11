<?php
declare(strict_types=1);

namespace test\winterframework\ppa;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\ppa\EntityRegistry;
use dev\winterframework\ppa\PpaObjectMapper;
use dev\winterframework\util\log\Wlf4p;
use PHPUnit\Framework\TestCase;
use test\winterframework\lib\TestUtil;
use test\winterframework\ppa\classes\TestPpaAccount01;
use test\winterframework\TestPdbcApplication;

class PpaTests extends TestCase {
    use Wlf4p;

    public function testPpa01() {
        /** @var ApplicationContextData $ctxData */
        /** @var ApplicationContext $appCtx */
        list($ctxData, $appCtx) = TestUtil::getApplicationContext(TestPdbcApplication::class);

        $appCtx->addClass(TestPpaAccount01::class);

        $data = [
            'ACCOUNT_ID' => 100,
            'ACCOUNT_NAME' => 'Suvera',
            'CREATED_ON' => '2021-06-08',
            'BALANCE' => 10.05,
        ];

        /** @var TestPpaAccount01 $account */
        $account = PpaObjectMapper::createObject(TestPpaAccount01::class, $data);
        $this->assertSame($account->getBalance(), 10.05);

        $entity = EntityRegistry::getEntity(TestPpaAccount01::class);
        $sql = PpaObjectMapper::generateInsertSql($account, $entity);
        $this->assertSame($sql[0], 'insert into ACCOUNT (ACCOUNT_ID, ACCOUNT_NAME, CREATED_ON, BALANCE ) '
            . 'values (:b_id, :b_name, :b_created, :b_balance) ');

        $sql = PpaObjectMapper::generateUpdateSql($account, $entity);
        $this->assertSame($sql[0], 'update ACCOUNT set ACCOUNT_ID = :b_id, ACCOUNT_NAME = :b_name, '
            . 'CREATED_ON = :b_created, BALANCE = :b_balance where ACCOUNT_ID = :b_id');

        $sql = PpaObjectMapper::generateDeleteSql($account, $entity);
        $this->assertSame($sql[0], 'delete from ACCOUNT where ACCOUNT_ID = :b_id');

    }
}