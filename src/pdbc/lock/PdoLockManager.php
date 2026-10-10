<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\lock;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\util\concurrent\StoreLockManager;

/**
 * Distributed #[Lockable] locking in a database table (see PdoLockStore).
 *
 *   #[Bean('dbLockManager')]
 *   public function dbLockManager(DataSource $ds): LockManager {
 *       return new PdoLockManager($ds);
 *   }
 *
 *   #[Lockable(name: 'order-#{id}', ttlSeconds: 30, lockManager: 'dbLockManager')]
 */
class PdoLockManager extends StoreLockManager {

    /**
     * @param int $pollMs pause between attempts while waiting (waitMilliSecs)
     */
    public function __construct(
        DataSource $dataSource,
        string $table = PdoLockStore::DEFAULT_TABLE,
        bool $createTable = true,
        int $pollMs = 50,
    ) {
        parent::__construct(new PdoLockStore($dataSource, $table, $createTable), $pollMs);
    }
}
