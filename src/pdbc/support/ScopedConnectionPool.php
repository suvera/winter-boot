<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\coroutine\PoolExhaustedException;
use dev\winterframework\pdbc\Connection;
use dev\winterframework\pdbc\ex\CannotGetConnectionException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Coroutine-scoped connection pool shared by PdoDataSource and OciDataSource.
 *
 * - Inside a Swoole coroutine every coroutine owns one connection until it
 *   ends; outside coroutines a single shared connection is used.
 * - Connections returned to the pool are reset first (open transactions
 *   rolled back); a connection that cannot be reset is discarded.
 * - Failures fail closed: a coroutine never falls back to the shared
 *   connection, which would interleave statements of concurrent requests.
 * - maxConnections caps open scoped and isolated connections, counting
 *   connections that are still being opened, so concurrent checkouts cannot
 *   overshoot it. A waiter takes the connection whose release woke it.
 * - beginIsolation()/endIsolation() bind a dedicated connection to the
 *   current scope (REQUIRES_NEW / NOT_SUPPORTED propagation).
 *
 * Users provide createConnection(), coroutineDbEnabled and the
 * DataSourceConfig in $config.
 */
trait ScopedConnectionPool {

    /** @var array<int, Connection> coroutine id => checked-out connection */
    private array $scopedConnections = [];

    /** @var Connection[] returned connections available for reuse */
    private array $idleConnections = [];

    /** @var array<int, bool> coroutines whose release hook is registered */
    private array $deferRegistered = [];

    /** @var array<string, Connection[]> scope key => isolation stack */
    private array $isolated = [];

    /** connections currently being opened, counted against the cap */
    private int $pendingConnections = 0;

    private ?Channel $releaseSignals = null;

    abstract protected function createConnection(): Connection;

    protected function pooledConnection(): Connection {
        $cid = $this->currentCoroutineId();
        $key = $cid === null ? 'process' : 'c' . $cid;
        if (!empty($this->isolated[$key])) {
            return $this->isolated[$key][array_key_last($this->isolated[$key])];
        }
        if ($cid === null) {
            return $this->sharedConnection();
        }
        try {
            return $this->scopedConnection($cid);
        } catch (PoolExhaustedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CannotGetConnectionException(
                'Could not obtain a database connection for this coroutine', 0, $e
            );
        }
    }

    public function beginIsolation(): Connection {
        $cid = $this->currentCoroutineId();
        $key = $cid === null ? 'process' : 'c' . $cid;
        // Inside a coroutine an isolated connection counts against
        // maxConnections like any scoped one; the process scope is uncapped.
        $conn = $cid === null ? ($this->takeIdle() ?? $this->createConnection()) : $this->checkoutConnection();
        $this->isolated[$key][] = $conn;
        if ($cid !== null) {
            $this->registerRelease($cid);
        }
        return $conn;
    }

    public function endIsolation(): void {
        $cid = $this->currentCoroutineId();
        $key = $cid === null ? 'process' : 'c' . $cid;
        if (empty($this->isolated[$key])) {
            return;
        }
        $conn = array_pop($this->isolated[$key]);
        if (empty($this->isolated[$key])) {
            unset($this->isolated[$key]);
        }
        $this->recycle($conn);
        $this->signalRelease();
    }

    public function checkIdleConnection(): void {
        foreach ($this->idleConnections as $key => $conn) {
            try {
                $conn->checkIdleConnection();
            } catch (Throwable $e) {
                self::logException($e);
            }
            if ($conn->isClosed()) {
                unset($this->idleConnections[$key]);
            }
        }
        if (isset($this->connection)) {
            try {
                $this->connection->checkIdleConnection();
            } catch (Throwable $e) {
                self::logException($e);
            }
        }
    }

    private function currentCoroutineId(): ?int {
        if (!$this->coroutineDbEnabled) {
            return null;
        }
        if (!extension_loaded('swoole') || !class_exists(Coroutine::class)) {
            return null;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return null;
        }
        if ($cid === false || $cid === -1) {
            return null;
        }
        return (int)$cid;
    }

    private function scopedConnection(int $cid): Connection {
        $existing = $this->scopedConnections[$cid] ?? null;
        if ($existing !== null) {
            if (!$existing->isClosed()) {
                $existing->touch();
                return $existing;
            }
            unset($this->scopedConnections[$cid]);
        }

        $conn = $this->checkoutConnection();
        $this->scopedConnections[$cid] = $conn;
        $this->registerRelease($cid);
        return $conn;
    }

    /**
     * Reuse an idle connection, or open a new one once the cap allows it.
     * A connection released while waiting is taken, never left idle while
     * a new one is opened beside it.
     */
    private function checkoutConnection(): Connection {
        $conn = $this->takeIdle();
        if ($conn !== null) {
            return $conn;
        }
        $this->awaitSlot();
        $conn = $this->takeIdle();
        if ($conn !== null) {
            return $conn;
        }
        $this->pendingConnections++;
        try {
            return $this->createConnection();
        } finally {
            $this->pendingConnections--;
        }
    }

    private function registerRelease(int $cid): void {
        if (isset($this->deferRegistered[$cid])) {
            return;
        }
        $this->deferRegistered[$cid] = true;
        try {
            Coroutine::defer(function () use ($cid): void {
                $this->releaseConnection($cid);
            });
        } catch (Throwable $e) {
            unset($this->deferRegistered[$cid]);
            self::logException($e, 'Could not register coroutine connection release');
        }
    }

    private function releaseConnection(int $cid): void {
        unset($this->deferRegistered[$cid]);
        $conn = $this->scopedConnections[$cid] ?? null;
        unset($this->scopedConnections[$cid]);
        if ($conn !== null) {
            $this->recycle($conn);
        }
        $key = 'c' . $cid;
        foreach (array_reverse($this->isolated[$key] ?? []) as $isolatedConn) {
            $this->recycle($isolatedConn);
        }
        unset($this->isolated[$key]);
        $this->signalRelease();
    }

    /**
     * Return a connection to the idle list only when it is clean: an open
     * transaction left by the previous owner is rolled back, and a
     * connection that cannot be reset is closed and dropped.
     */
    private function recycle(Connection $conn): void {
        if ($conn->isClosed()) {
            return;
        }
        $clean = true;
        if ($conn instanceof ResettableConnection) {
            $clean = $conn->resetForReuse();
        }
        if ($clean) {
            $this->idleConnections[] = $conn;
            return;
        }
        try {
            $conn->close(true);
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    private function takeIdle(): ?Connection {
        while (!empty($this->idleConnections)) {
            $conn = array_pop($this->idleConnections);
            if (!$conn->isClosed()) {
                $conn->touch();
                return $conn;
            }
        }
        return null;
    }

    private function sharedConnection(): Connection {
        if (!isset($this->connection)) {
            $this->connection = $this->createConnection();
        }
        return $this->connection;
    }

    private function effectiveMaxConnections(): int {
        return max(0, $this->config->getMaxConnections());
    }

    private function effectiveMaxWaitMs(): int {
        return max(0, $this->config->getMaxWaitMs());
    }

    private function activeConnectionCount(): int {
        $isolated = 0;
        foreach ($this->isolated as $key => $stack) {
            if ($key !== 'process') {
                $isolated += count($stack);
            }
        }
        return count($this->scopedConnections) + $isolated + $this->pendingConnections;
    }

    private function slotAvailable(int $max): bool {
        return !empty($this->idleConnections) || $this->activeConnectionCount() < $max;
    }

    private function awaitSlot(): void {
        $max = $this->effectiveMaxConnections();
        if ($max <= 0) {
            return;
        }
        $deadline = microtime(true) + $this->effectiveMaxWaitMs() / 1000;
        while (!$this->slotAvailable($max)) {
            $remainingMs = (int)(($deadline - microtime(true)) * 1000);
            if ($remainingMs <= 0 || !$this->waitForRelease($remainingMs)) {
                // A release may have landed without a signal reaching us.
                if ($this->slotAvailable($max)) {
                    return;
                }
                throw new PoolExhaustedException(
                    $this->config->getName(),
                    $this->activeConnectionCount(),
                    $max
                );
            }
        }
    }

    private function waitForRelease(int $timeoutMs): bool {
        if (!extension_loaded('swoole') || !class_exists(Channel::class)) {
            return false;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return false;
        }
        if ($cid === false || $cid === -1) {
            return false;
        }
        if ($this->releaseSignals === null) {
            try {
                $this->releaseSignals = new Channel(1);
            } catch (Throwable) {
                return false;
            }
        }
        try {
            return $this->releaseSignals->pop(max($timeoutMs, 1) / 1000) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function signalRelease(): void {
        if ($this->releaseSignals === null) {
            return;
        }
        if (!extension_loaded('swoole')) {
            return;
        }
        try {
            $cid = Coroutine::getCid();
        } catch (Throwable) {
            return;
        }
        if ($cid === false || $cid === -1) {
            return;
        }
        try {
            if ($this->releaseSignals->isFull()) {
                return;
            }
            $this->releaseSignals->push(true);
        } catch (Throwable) {
        }
    }
}
