<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\pdbc\support\TransactionObject;
use dev\winterframework\txn\Savepoint;

class PdoTransactionObject extends TransactionObject {

    public function __construct(
        private PdoConnection $connection
    ) {
        parent::__construct($connection);
    }

    public function rollbackToSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by PDOTransaction Manager');
    }

    public function releaseSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by PDOTransaction Manager');
    }

    public function createSavepoint(): Savepoint {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by PDOTransaction Manager');
    }

    public function isRollbackOnly(): bool {
        return true;
    }

    public function flush(): void {
        $this->connection->commit();
    }
}