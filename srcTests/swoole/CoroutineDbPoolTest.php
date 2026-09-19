<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use dev\winterframework\coroutine\PoolExhaustedException;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\pdo\PdoConnection;
use dev\winterframework\pdbc\pdo\PdoDataSource;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

class CoroutineDbPoolTest {

    private function sqliteConfig(int $maxConnections = 50, int $maxWaitMs = 5000): DataSourceConfig {
        $config = new DataSourceConfig();
        $config->setName('test');
        $config->setUrl('sqlite::memory:');
        $config->setMaxConnections($maxConnections);
        $config->setMaxWaitMs($maxWaitMs);
        return $config;
    }

    public function testPoolConfigDefaults(): void {
        $config = new DataSourceConfig();
        if ($config->getMaxConnections() !== 50) {
            throw new \Exception('expected default maxConnections 50');
        }
        if ($config->getMaxWaitMs() !== 5000) {
            throw new \Exception('expected default maxWaitMs 5000');
        }
    }

    public function testSharedSingletonOutsideCoroutine(): void {
        $ds = new PdoDataSource($this->sqliteConfig());
        $first = $ds->getConnection();
        $second = $ds->getConnection();
        if ($first !== $second) {
            throw new \Exception('expected shared singleton outside coroutines');
        }
    }

    public function testSameCoroutineReusesConnection(): void {
        $ds = new PdoDataSource($this->sqliteConfig());
        $pair = null;
        run(function () use ($ds, &$pair): void {
            $pair = [$ds->getConnection(), $ds->getConnection()];
        });
        if ($pair[0] !== $pair[1]) {
            throw new \Exception('expected same-coroutine connection reuse');
        }
    }

    public function testCoroutinesGetIsolatedConnections(): void {
        $ds = new PdoDataSource($this->sqliteConfig());
        $conns = [];
        run(function () use ($ds, &$conns): void {
            Coroutine::create(function () use ($ds, &$conns): void {
                $conns['a'] = $ds->getConnection();
            });
            Coroutine::create(function () use ($ds, &$conns): void {
                $conns['b'] = $ds->getConnection();
            });
        });
        if (!isset($conns['a'], $conns['b'])) {
            throw new \Exception('expected both coroutines to check out a connection');
        }
        if ($conns['a'] === $conns['b']) {
            throw new \Exception('expected isolated connections per coroutine');
        }
    }

    public function testReleasedConnectionIsReused(): void {
        $ds = new PdoDataSource($this->sqliteConfig());
        $first = null;
        $second = null;
        run(function () use ($ds, &$first): void {
            $first = $ds->getConnection();
        });
        run(function () use ($ds, &$second): void {
            $second = $ds->getConnection();
        });
        if ($first !== $second) {
            throw new \Exception('expected released connection to be reused');
        }
        if ($second->isClosed()) {
            throw new \Exception('expected reused connection to be open');
        }
    }

    public function testPoolExhaustionThrows(): void {
        // maxWaitMs 0 fails fast without any Channel wait: this Swoole
        // build segfaults when a timed Channel pop follows earlier waits.
        $ds = new PdoDataSource($this->sqliteConfig(1, 0));
        $filler = new PdoConnection('sqlite::memory:', '', '', []);
        $exhausted = null;
        run(function () use ($ds, $filler, &$exhausted): void {
            $prop = new \ReflectionProperty($ds, 'scopedConnections');
            $prop->setValue($ds, [999 => $filler]);
            try {
                $ds->getConnection();
            } catch (PoolExhaustedException $e) {
                $exhausted = $e;
            }
        });
        if (!$exhausted instanceof PoolExhaustedException) {
            throw new \Exception('expected PoolExhaustedException on pool exhaustion');
        }
        if ($exhausted->getMaxDelegates() !== 1 || $exhausted->getActiveDelegates() !== 1) {
            throw new \Exception('expected exhaustion to report active=1 max=1');
        }
    }

    public function testIdleCheckNeverReapsCheckedOutConnection(): void {
        $config = $this->sqliteConfig();
        $config->setIdleTimeout(1);
        $ds = new PdoDataSource($config);
        $result = null;
        run(function () use ($ds, &$result): void {
            $conn = $ds->getConnection();
            // Backdate instead of sleeping: this Swoole build segfaults at
            // shutdown when timer-based waits are used in tests.
            $backdate = new \ReflectionProperty($conn, 'lastAccessTime');
            $backdate->setValue($conn, time() - 3600);
            $ds->checkIdleConnection();
            $result = [$conn, $ds->getConnection(), $conn->isClosed()];
        });
        if ($result[0] !== $result[1]) {
            throw new \Exception('expected checked-out connection to survive idle check');
        }
        if ($result[2]) {
            throw new \Exception('expected checked-out connection to stay open across idle check');
        }
    }
}
