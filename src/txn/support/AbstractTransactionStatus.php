<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\pdbc\support\TransactionObject;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionStatus;

abstract class AbstractTransactionStatus implements TransactionStatus {
    protected bool $completed = false;
    protected Savepoint $savepoint;

    public function __construct(
        private TransactionObject $transaction
    ) {
    }

    public function isCompleted(): bool {
        return $this->completed;
    }

    public function setCompleted(bool $completed): void {
        $this->completed = $completed;
    }

    public function getSavepoint(): Savepoint {
        return $this->savepoint;
    }

    public function setSavepoint(Savepoint $savepoint): void {
        $this->savepoint = $savepoint;
    }

}
