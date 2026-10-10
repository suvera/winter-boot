<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/McpInvokerTest.php';

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\lock\PdoLockManager;
use dev\winterframework\pdbc\lock\PdoLockStore;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\concurrent\DefaultLockManager;
use dev\winterframework\util\concurrent\Lock;
use dev\winterframework\util\concurrent\LockableAspect;
use dev\winterframework\util\concurrent\LockException;
use dev\winterframework\util\concurrent\LockManager;
use dev\winterframework\util\concurrent\Locks;
use dev\winterframework\util\concurrent\LockStore;
use dev\winterframework\util\concurrent\StoreLockManager;
use ReflectionMethod;
use TypeError;
use winterBootTests\Support\TestCase;

/** In-memory LockStore with a controllable clock. */
final class LockTestMemoryStore implements LockStore {
    /** @var array<string, array{0: string, 1: int}> name => [owner, expiresAt] */
    public array $rows = [];
    public int $now = 1000;

    public function acquire(string $name, string $owner, int $ttlMs): bool {
        $row = $this->rows[$name] ?? null;
        if ($row !== null && ($row[1] === 0 || $row[1] >= $this->now)) {
            return false;
        }
        $this->rows[$name] = [$owner, $ttlMs > 0 ? $this->now + $ttlMs : 0];
        return true;
    }

    public function release(string $name, string $owner): bool {
        if (($this->rows[$name][0] ?? null) !== $owner) {
            return false;
        }
        unset($this->rows[$name]);
        return true;
    }

    public function refresh(string $name, string $owner, int $ttlMs): bool {
        if (($this->rows[$name][0] ?? null) !== $owner) {
            return false;
        }
        $this->rows[$name][1] = $ttlMs > 0 ? $this->now + $ttlMs : 0;
        return true;
    }
}

final class LockTestRecordingManager implements LockManager {
    public array $waits = [];

    public function provideLock(string $name, int $ttl = 0): Lock {
        $mgr = $this;
        return new class($name, $mgr) implements Lock {
            public function __construct(private string $name, private LockTestRecordingManager $mgr) {
            }

            public function tryLock(int $waitForMs = 0): bool {
                $this->mgr->waits[] = $waitForMs;
                return true;
            }

            public function isLocked(): bool {
                return true;
            }

            public function unlock(): void {
            }

            public function update(int $ttl = 0): void {
            }

            public function getName(): string {
                return $this->name;
            }

            public function isDistributed(): bool {
                return false;
            }
        };
    }

    public function removeLock(Lock|string $lock): bool {
        return true;
    }

    public function updateLock(Lock|string $lock, int $ttl = 0): bool {
        return true;
    }

    public function unLock(string|Lock $lock): bool {
        return true;
    }

    public function getLocks(): Locks {
        return new Locks();
    }
}

#[Service]
final class LockTestService {
    public function work(int $id): void {
    }
}

/**
 * #[Lockable] and the lock managers: per-caller handles, bean-name
 * managers, waitMilliSecs, and the database lease store.
 */
final class LockManagerTest extends TestCase {

    private array $files = [];

    public function __destruct() {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function sqliteFile(): string {
        $file = tempnam(sys_get_temp_dir(), 'wblock-') . '.sqlite';
        $this->files[] = $file;
        return $file;
    }

    /** A separate DataSource on the same file stands in for another pod. */
    private function pod(string $file): PdoDataSource {
        $config = new DataSourceConfig();
        $config->setName('lock-' . basename($file));
        $config->setUrl('sqlite:' . $file);
        return new PdoDataSource($config);
    }

    // ------------------------------------------------- default local manager

    public function testDefaultManagerHandlesExcludeEachOther(): void {
        $mgr = new DefaultLockManager();
        $name = 'wb-local-' . bin2hex(random_bytes(4));
        $a = $mgr->provideLock($name);
        $b = $mgr->provideLock($name);
        $this->assertFalse($a === $b, 'each caller gets its own handle');
        $this->assertTrue($a->tryLock());
        try {
            $this->assertFalse($b->tryLock(0), 'second caller must not acquire a held lock');
            $this->assertTrue(isset($mgr->getLocks()[$name]) && count($mgr->getLocks()) === 1, 'held lock listed');
        } finally {
            $a->unlock();
        }
        $this->assertTrue($b->tryLock(0));
        $b->unlock();
        $this->assertSame(0, count($mgr->getLocks()));
    }

    public function testDefaultManagerRegistryDoesNotGrowWithNames(): void {
        $mgr = new DefaultLockManager();
        for ($i = 0; $i < 200; $i++) {
            $l = $mgr->provideLock('wb-grow-' . $i . '-' . getmypid());
            $l->tryLock();
            $l->unlock();
        }
        $this->assertSame(0, count($mgr->getLocks()));
    }

    // -------------------------------------------------------- #[Lockable]

    public function testLockableAcceptsBeanNames(): void {
        $ref = RefMethod::getInstance(new ReflectionMethod(LockTestService::class, 'work'));
        (new Lockable(name: 'order-#{id}', lockManager: 'redisLockManager'))->init($ref);
        (new Lockable(name: 'order-#{id}', lockManager: StoreLockManager::class))->init($ref);
        $this->assertThrows(TypeError::class, function () use ($ref) {
            (new Lockable(name: 'x', lockManager: \stdClass::class))->init($ref);
        }, 'a class that is not a LockManager');
        $this->assertThrows(TypeError::class, function () use ($ref) {
            (new Lockable(name: 'x', waitMilliSecs: -1))->init($ref);
        });
    }

    public function testAspectUsesNamedBeanAndWaitTime(): void {
        $recording = new LockTestRecordingManager();
        $ctx = new McpTestCtx([], ['dbLockManager' => $recording]);
        $method = RefMethod::getInstance(new ReflectionMethod(LockTestService::class, 'work'));
        $stereo = new Lockable(name: 'order-#{id}', waitMilliSecs: 750, lockManager: 'dbLockManager');
        $stereo->init($method);
        $aspect = new LockableAspect();
        $exCtx = new AopExecutionContext(new LockTestService(), [7]);
        $aspect->begin(new AopContext($stereo, $method, $ctx), $exCtx);
        $this->assertSame([750], $recording->waits);
    }

    // ------------------------------------------------- store-based handles

    public function testStoreLockOwnershipAndExpiry(): void {
        $store = new LockTestMemoryStore();
        $mgr = new StoreLockManager($store, 1);
        $a = $mgr->provideLock('job', 2);
        $b = $mgr->provideLock('job', 2);
        $this->assertTrue($a->tryLock());
        $this->assertFalse($b->tryLock());
        $b->unlock();                                   // not held: must not release A's lease
        $this->assertTrue(isset($store->rows['job']));

        $store->now += 3000;                            // A's 2 s lease ran out
        $this->assertTrue($b->tryLock(), 'expired lease can be taken over');
        $this->assertThrows(LockException::class, fn() => $a->update(2), 'A lost the lease');
        $this->assertFalse($a->isLocked());
        $a->unlock();
        $this->assertTrue(isset($store->rows['job']), 'A must not release B\'s lease');
        $b->unlock();
        $this->assertFalse(isset($store->rows['job']));
    }

    // ---------------------------------------------------- database leases

    public function testPdoLocksExcludeAcrossPods(): void {
        $file = $this->sqliteFile();
        $pod1 = new PdoLockManager($this->pod($file), pollMs: 5);
        $pod2 = new PdoLockManager($this->pod($file), pollMs: 5);

        $a = $pod1->provideLock('order-7', 30);
        $b = $pod2->provideLock('order-7', 30);
        $this->assertTrue($a->tryLock());
        $this->assertFalse($b->tryLock(30), 'other pod must wait');
        $this->assertTrue($pod2->provideLock('order-8', 30)->tryLock(), 'other names are free');
        $a->unlock();
        $this->assertTrue($b->tryLock(0));
        $b->unlock();
    }

    public function testPdoExpiredLeaseIsTakenOverAndOwnerIsChecked(): void {
        $file = $this->sqliteFile();
        $ds = $this->pod($file);
        $store = new PdoLockStore($ds);
        $this->assertTrue($store->acquire('k', 'owner-a', 1));
        usleep(5000);
        $this->assertTrue($store->acquire('k', 'owner-b', 60000), 'lease of 1 ms has expired');
        $this->assertFalse($store->release('k', 'owner-a'), 'old owner cannot release');
        $this->assertFalse($store->refresh('k', 'owner-a', 1000), 'old owner cannot extend');
        $this->assertTrue($store->refresh('k', 'owner-b', 1000));
        $this->assertFalse($store->acquire('k', 'owner-c', 0), 'unexpired lease is kept');
        $this->assertTrue($store->release('k', 'owner-b'));
        $this->assertTrue($store->acquire('k', 'owner-c', 0));
    }

    public function testPdoLockIsVisibleWhileCallerIsInATransaction(): void {
        $file = $this->sqliteFile();
        $ds1 = $this->pod($file);
        new PdoLockStore($ds1);                         // make sure the table exists
        (new PdoLockManager($ds1))->provideLock('warmup')->tryLock();

        $txn = new PdoTransactionManager($ds1);
        $status = $txn->getTransaction(new DefaultTransactionDefinition(Transaction::PROPAGATION_REQUIRED));
        try {
            $a = (new PdoLockManager($ds1))->provideLock('in-txn', 30);
            $this->assertTrue($a->tryLock());
            // The lock row is already committed, although the caller's transaction is open.
            $b = (new PdoLockManager($this->pod($file)))->provideLock('in-txn', 30);
            $this->assertFalse($b->tryLock(0), 'lock must be visible to other pods at once');
            $a->unlock();
        } finally {
            $txn->rollback($status);
        }
        $this->assertTrue((new PdoLockManager($this->pod($file)))->provideLock('in-txn', 30)->tryLock(0),
            'rolling back the caller\'s transaction must not bring a lock back');
    }

    public function testPdoLongNamesAreHashed(): void {
        $long = str_repeat('x', 500);
        $this->assertSame(64 + 7, strlen(PdoLockStore::key($long)));
        $this->assertSame('short', PdoLockStore::key('short'));
        $mgr = new PdoLockManager($this->pod($this->sqliteFile()));
        $l = $mgr->provideLock($long, 10);
        $this->assertTrue($l->tryLock());
        $l->unlock();
    }
}
