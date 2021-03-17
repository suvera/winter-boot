<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\support\DataSourceTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;

class OciTransactionManager extends DataSourceTransactionManager {

    protected function doCommit(TransactionStatus $status): void {
        /** @var OciTransactionStatus $status */
        TypeAssert::typeOf($status, OciTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): OciTransactionStatus {
        $conn = $this->getDataSource()->getConnection();

        /** @var OciConnection $conn */
        $txn = new OciTransactionObject($conn);
        $txn->setReadOnly($definition->isReadOnly());

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $status = new OciTransactionStatus(
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
