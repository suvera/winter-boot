<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface PlatformTransactionManager {

    public function commit(TransactionStatus $status): void;

    public function getTransaction(TransactionDefinition $definition): TransactionStatus;

    public function rollback(TransactionStatus $status): void;
}