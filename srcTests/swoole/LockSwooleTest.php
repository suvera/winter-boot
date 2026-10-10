<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\lock\PdoLockManager;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use dev\winterframework\util\concurrent\DefaultLockManager;
use dev\winterframework\util\concurrent\LockManager;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

/**
 * 2.1.6: locks obtained through a LockManager (as #[Lockable] does) must
 * exclude coroutines of the same worker, and waiting must not block it.
 */
class LockSwooleTest {

    private function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \Exception($message);
        }
    }

    /**
     * Five coroutines enter a section guarded by the same lock name, each
     * waiting up to 2 s. Inside, a coroutine yields; if another one got in
     * meanwhile, the sections overlapped.
     */
    private function assertExclusive(LockManager $mgr, string $name): void {
        $inside = 0;
        $maxInside = 0;
        $acquired = 0;
        $ticks = 0;
        Coroutine::set(['hook_flags' => 0]);
        run(function () use ($mgr, $name, &$inside, &$maxInside, &$acquired, &$ticks): void {
            // Proves waiting yields: this coroutine keeps ticking meanwhile.
            Coroutine::create(function () use (&$ticks): void {
                for ($i = 0; $i < 20; $i++) {
                    $ticks++;
                    Coroutine::sleep(0.005);
                }
            });
            for ($i = 0; $i < 5; $i++) {
                Coroutine::create(function () use ($mgr, $name, &$inside, &$maxInside, &$acquired): void {
                    $lock = $mgr->provideLock($name, 10);
                    if (!$lock->tryLock(2000)) {
                        return;
                    }
                    $acquired++;
                    $inside++;
                    $maxInside = max($maxInside, $inside);
                    Coroutine::sleep(0.01);
                    $inside--;
                    $lock->unlock();
                });
            }
        });
        $this->check($acquired === 5, 'every coroutine must get the lock in turn, got ' . $acquired);
        $this->check($maxInside === 1, 'sections overlapped: ' . $maxInside . ' coroutines inside at once');
        $this->check($ticks === 20, 'waiting blocked the worker');
    }

    public function testDefaultManagerExcludesCoroutines(): void {
        $this->assertExclusive(new DefaultLockManager(), 'wb-co-' . bin2hex(random_bytes(4)));
    }

    public function testPdoManagerExcludesCoroutines(): void {
        $file = tempnam(sys_get_temp_dir(), 'wblock-co-') . '.sqlite';
        try {
            $config = new DataSourceConfig();
            $config->setName('lock-co');
            $config->setUrl('sqlite:' . $file);
            $this->assertExclusive(new PdoLockManager(new PdoDataSource($config), pollMs: 5), 'order-1');
        } finally {
            @unlink($file);
        }
    }
}
