<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\coroutine\CoroutineScopeProviders;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\ex\CannotGetConnectionException;
use dev\winterframework\pdbc\pdo\PdoConnection;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\async\AsyncInMemoryQueue;
use dev\winterframework\util\async\AsyncQueueRecord;
use dev\winterframework\util\concurrent\LocalLock;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

/**
 * 2.1.1 regressions that need real coroutines. Each method fails on 2.1.0.
 */
class Fix211SwooleTest {

    private function dataSource(string $url = 'sqlite::memory:'): PdoDataSource {
        $config = new DataSourceConfig();
        $config->setName('fix211');
        $config->setUrl($url);
        return new PdoDataSource($config);
    }

    private function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \Exception($message);
        }
    }

    // A coroutine must not join another coroutine's open transaction.
    public function testConcurrentCoroutinesGetSeparateTransactions(): void {
        CoroutineScopeProviders::resetShared();
        $mgr = new PdoTransactionManager($this->dataSource());
        $seen = [];
        run(function () use ($mgr, &$seen): void {
            $aStarted = new Channel(1);
            $release = new Channel(1);
            Coroutine::create(function () use ($mgr, &$seen, $aStarted, $release): void {
                $status = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
                $seen['a'] = $status->isNewTransaction();
                $aStarted->push(true);
                $release->pop();
                $mgr->commit($status);
            });
            // B starts only once A holds an open transaction.
            $aStarted->pop();
            Coroutine::create(function () use ($mgr, &$seen, $release): void {
                $status = $mgr->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
                $seen['b'] = $status->isNewTransaction();
                $mgr->commit($status);
                $release->push(true);
            });
        });
        $this->check(($seen['a'] ?? false) === true, 'coroutine A must start a transaction');
        $this->check(($seen['b'] ?? false) === true, 'coroutine B must start its own transaction, not join A');
    }

    // A connection left mid-transaction by an ended coroutine is rolled back before reuse.
    public function testConnectionResetWhenCoroutineEnds(): void {
        $ds = $this->dataSource();
        $first = null;
        $inTxn = null;
        run(function () use ($ds, &$first): void {
            $first = $ds->getConnection();
            $first->beginTransaction();
        });
        run(function () use ($ds, &$inTxn): void {
            $inTxn = $ds->getConnection()->getPdo()->inTransaction();
        });
        $this->check($inTxn === false, 'reused connection must not carry the previous transaction');
    }

    // A failed checkout inside a coroutine throws instead of returning the shared connection.
    public function testCoroutineCheckoutFailsClosed(): void {
        $ds = $this->dataSource('sqlite:/nonexistent-dir-wb211/db.sqlite');
        $shared = new PdoConnection('sqlite::memory:', '', '', []);
        (new \ReflectionProperty($ds, 'connection'))->setValue($ds, $shared);
        $got = null;
        $error = null;
        run(function () use ($ds, &$got, &$error): void {
            try {
                $got = $ds->getConnection();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        $this->check($got !== $shared, 'coroutine must never receive the shared connection');
        $this->check($error instanceof CannotGetConnectionException, 'expected CannotGetConnectionException');
    }

    // Two coroutines in one worker must not both hold the same LocalLock.
    // File hooks are off so flock() is the real non-blocking syscall (with
    // hooks it yields and the second caller simply waits its turn).
    public function testLocalLockAcrossCoroutines(): void {
        $name = 'fix211-co-' . bin2hex(random_bytes(4));
        $result = [];
        Coroutine::set(['hook_flags' => 0]);
        run(function () use ($name, &$result): void {
            $a = new LocalLock($name);
            $result['a'] = $a->tryLock();
            Coroutine::create(function () use ($name, &$result): void {
                $result['b'] = (new LocalLock($name))->tryLock(0);
            });
            $a->unlock();
        });
        $this->check($result['a'] === true, 'first coroutine must lock');
        $this->check($result['b'] === false, 'second coroutine must not lock while held');
    }

    // A full in-memory async queue reports the overflow instead of dropping silently.
    public function testFullAsyncQueueReportsOverflow(): void {
        $ctx = new class extends \dev\winterframework\core\context\WinterApplicationContextBuilder {
            public function __construct() {
            }

            public function getId(): string {
                return 'test';
            }

            public function getApplicationName(): string {
                return 'test';
            }

            public function getApplicationVersion(): string {
                return 'test';
            }

            public function getStartupDate(): int {
                return 0;
            }
        };
        /** @var ApplicationContext $ctx */
        $queue = new AsyncInMemoryQueue($ctx, 1, 1, 64);
        $thrown = false;
        try {
            for ($i = 0; $i < 64; $i++) {
                $queue->enqueue(AsyncQueueRecord::fromArray(0, [
                    'className' => 'C', 'methodName' => 'm', 'timestamp' => 0,
                    'arguments' => '{}', 'workerId' => 1,
                ]));
            }
        } catch (\OverflowException) {
            $thrown = true;
        }
        $this->check($thrown, 'expected OverflowException once the table is full');
    }
}
