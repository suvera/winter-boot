<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\lock;

use dev\winterframework\core\System;
use dev\winterframework\exception\WinterException;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\pdo\PdoConnection;
use dev\winterframework\pdbc\support\IsolatedConnectionProvider;
use dev\winterframework\util\concurrent\LockStore;
use dev\winterframework\util\log\Wlf4p;
use PDO;
use PDOException;
use Throwable;

/**
 * Lock leases in a database table, shared by every pod using the same
 * database. One row per held lock:
 *
 *   lock_name  VARCHAR(191) PRIMARY KEY   (names longer than 191 bytes are hashed)
 *   owner      VARCHAR(64)                random token of the holding handle
 *   expires_at BIGINT                     epoch milliseconds; 0 = until released
 *
 * Every statement runs on its own isolated connection and commits at once,
 * so a lock is visible to other pods immediately, even when the caller is
 * inside a database transaction. The primary key makes acquiring atomic.
 * Expiry uses the application clock, so keep pod clocks in sync (NTP).
 *
 * Portable SQL: PostgreSQL, MySQL/MariaDB and SQLite (CREATE TABLE IF NOT
 * EXISTS). For other databases create the table yourself and pass
 * $createTable = false.
 */
class PdoLockStore implements LockStore {
    use Wlf4p;

    public const DEFAULT_TABLE = 'winter_locks';
    public const MAX_NAME_BYTES = 191;

    private bool $tableReady;

    public function __construct(
        private readonly DataSource $dataSource,
        private readonly string $table = self::DEFAULT_TABLE,
        bool $createTable = true,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)) {
            throw new WinterException('Invalid lock table name');
        }
        if (!$dataSource instanceof IsolatedConnectionProvider) {
            // Without isolation a lock row would join the caller's transaction and
            // stay invisible to other pods until it commits: not a lock at all.
            throw new WinterException('PdoLockStore needs a DataSource implementing '
                . IsolatedConnectionProvider::class . ' (PdoDataSource does)');
        }
        $this->tableReady = !$createTable;
    }

    /** The table's DDL, for creating it by hand. */
    public function getCreateTableSql(): string {
        return 'CREATE TABLE IF NOT EXISTS ' . $this->table . ' ('
            . 'lock_name VARCHAR(' . self::MAX_NAME_BYTES . ') NOT NULL PRIMARY KEY, '
            . 'owner VARCHAR(64) NOT NULL, '
            . 'expires_at BIGINT NOT NULL)';
    }

    public function acquire(string $name, string $owner, int $ttlMs): bool {
        $key = self::key($name);
        return $this->run(function (PDO $pdo) use ($key, $owner, $ttlMs): bool {
            $now = System::currentTimeMillis();
            $expires = $ttlMs > 0 ? $now + $ttlMs : 0;
            try {
                $this->inTxn($pdo, function () use ($pdo, $key, $owner, $expires) {
                    $st = $pdo->prepare('INSERT INTO ' . $this->table
                        . ' (lock_name, owner, expires_at) VALUES (?, ?, ?)');
                    $st->execute([$key, $owner, $expires]);
                });
                return true;
            } catch (PDOException $e) {
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
            // Held already: take it over only if the holder's lease has expired.
            return $this->inTxn($pdo, function () use ($pdo, $key, $owner, $expires, $now): bool {
                $st = $pdo->prepare('UPDATE ' . $this->table . ' SET owner = ?, expires_at = ?'
                    . ' WHERE lock_name = ? AND expires_at > 0 AND expires_at < ?');
                $st->execute([$owner, $expires, $key, $now]);
                return $st->rowCount() === 1;
            });
        });
    }

    public function release(string $name, string $owner): bool {
        $key = self::key($name);
        return $this->run(fn(PDO $pdo): bool => $this->inTxn($pdo, function () use ($pdo, $key, $owner): bool {
            $st = $pdo->prepare('DELETE FROM ' . $this->table . ' WHERE lock_name = ? AND owner = ?');
            $st->execute([$key, $owner]);
            return $st->rowCount() === 1;
        }));
    }

    public function refresh(string $name, string $owner, int $ttlMs): bool {
        $key = self::key($name);
        return $this->run(fn(PDO $pdo): bool => $this->inTxn($pdo, function () use ($pdo, $key, $owner, $ttlMs): bool {
            $now = System::currentTimeMillis();
            $st = $pdo->prepare('UPDATE ' . $this->table . ' SET expires_at = ?'
                . ' WHERE lock_name = ? AND owner = ? AND (expires_at = 0 OR expires_at >= ?)');
            $st->execute([$ttlMs > 0 ? $now + $ttlMs : 0, $key, $owner, $now]);
            return $st->rowCount() === 1;
        }));
    }

    /** Lock names as stored: names over the key size are hashed. */
    public static function key(string $name): string {
        return strlen($name) <= self::MAX_NAME_BYTES ? $name : 'sha256:' . hash('sha256', $name);
    }

    /**
     * Runs $fn on a dedicated connection taken from the pool for this call
     * only, outside any transaction of the current request.
     */
    private function run(callable $fn): mixed {
        /** @var IsolatedConnectionProvider&DataSource $ds */
        $ds = $this->dataSource;
        $conn = $ds->beginIsolation();
        try {
            if (!$conn instanceof PdoConnection) {
                throw new WinterException('PdoLockStore needs PDO connections');
            }
            $pdo = $conn->getPdo();
            // Duplicate-key detection needs exceptions; the pooled connection
            // gets its configured mode back before it is returned.
            $errMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            try {
                $this->ensureTable($pdo);
                return $fn($pdo);
            } finally {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, $errMode);
            }
        } finally {
            $ds->endIsolation();
        }
    }

    /** Commits each statement on its own, whatever the connection's autocommit setting. */
    private function inTxn(PDO $pdo, callable $fn): mixed {
        $pdo->beginTransaction();
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function ensureTable(PDO $pdo): void {
        if ($this->tableReady) {
            return;
        }
        try {
            $pdo->exec($this->getCreateTableSql());
        } catch (Throwable $e) {
            // The table may exist already, or the dialect lacks IF NOT EXISTS:
            // the first real statement will fail clearly if it is missing.
            self::logWarning('Could not create lock table ' . $this->table . ': ' . $e->getMessage());
        }
        $this->tableReady = true;
    }

    /** SQLSTATE class 23 = integrity constraint violation (duplicate primary key). */
    private static function isDuplicateKey(PDOException $e): bool {
        $state = (string)($e->errorInfo[0] ?? $e->getCode());
        return str_starts_with($state, '23');
    }
}
