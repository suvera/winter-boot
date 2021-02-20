<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface TransactionStatus {

    public function flush(): void;

    public function hasSavepoint(): bool;

    public function isCompleted(): bool;

    public function isRollbackOnly(): bool;

    public function isNewTransaction(): bool;

}
