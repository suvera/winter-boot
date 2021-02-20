<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\support\DataSourceTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;

class PdoTransactionManager extends DataSourceTransactionManager {

    public function __construct(
        protected PdoDataSource $dataSource
    ) {
        parent::__construct($dataSource);
    }

    public function getDataSource(): PdoDataSource {
        return $this->dataSource;
    }

    public function commit(TransactionStatus $status): void {
        /** @var PdoTransactionStatus $status */
        TypeAssert::typeOf($status, PdoTransactionStatus::class);
        $status->getTransaction()->getConnection()->commit();
    }

    public function getTransaction(TransactionDefinition $definition): PdoTransactionStatus {
        $conn = $this->getDataSource()->getConnection();
        $conn->beginTransaction();

        $txn = new PdoTransactionObject($conn);

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $status = new PdoTransactionStatus(
            $txn
        );

        return $status;
    }

    public function rollback(TransactionStatus $status): void {
        /** @var PdoTransactionStatus $status */
        TypeAssert::typeOf($status, PdoTransactionStatus::class);

        $status->getTransaction()->getConnection()->rollback();
    }

}
