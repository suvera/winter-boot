<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\support\DataSourceTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;

class PdoTransactionManager extends DataSourceTransactionManager {

    protected function doCommit(TransactionStatus $status): void {
        /** @var PdoTransactionStatus $status */
        TypeAssert::typeOf($status, PdoTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): PdoTransactionStatus {
        $conn = $this->getDataSource()->getConnection();

        /** @var PdoConnection $conn */
        $txn = new PdoTransactionObject($conn);
        $txn->setReadOnly($definition->isReadOnly());

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $status = new PdoTransactionStatus(
            $txn,
            true,
            $definition->isReadOnly()
        );

        return $status;
    }

    protected function doRollback(TransactionStatus $status): void {
        $status->getTransaction()->rollback();
    }

}
