<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\Connection;
use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\support\AbstractTransactionObject;

class PdoTransactionObject extends AbstractTransactionObject {

    public function __construct(
        private Connection $connection
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

}