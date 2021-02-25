<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface TransactionStatus {

    public function getTransaction(): ?TransactionObject;

    public function hasTransaction(): bool;

    public function flush(): void;

    public function hasSavepoint(): bool;

    public function isCompleted(): bool;

    public function isRollbackOnly(): bool;

    public function isNewTransaction(): bool;

    public function createAndHoldSavepoint(): void;

    public function rollbackToHeldSavepoint(): void;

    public function releaseHeldSavepoint(): void;

}
