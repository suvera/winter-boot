<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\Connection;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\support\AbstractTransactionObject;

class OciTransactionObject extends AbstractTransactionObject {

    public function __construct(
        private Connection $connection
    ) {
        parent::__construct($connection);

    }

    public function rollbackToSavepoint(Savepoint $point): void {
        $this->connection->rollback($point);
    }

    public function releaseSavepoint(Savepoint $point): void {
        $this->connection->releaseSavepoint($point);
    }

    public function createSavepoint(): Savepoint {
        return $this->connection->setSavepoint();
    }

}