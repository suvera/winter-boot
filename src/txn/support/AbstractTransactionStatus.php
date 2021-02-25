<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\txn\ex\NestedTransactionNotSupportedException;
use dev\winterframework\txn\ex\TransactionUsageException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionObject;
use dev\winterframework\txn\TransactionStatus;

abstract class AbstractTransactionStatus implements TransactionStatus {
    protected bool $completed = false;
    protected ?Savepoint $savepoint = null;

    public function __construct(
        protected ?TransactionObject $transaction = null,
        protected bool $newTransaction = true,
        protected bool $readOnly = false,
        protected bool $debug = false
    ) {
    }

    public function isNewTransaction(): bool {
        return $this->newTransaction;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function isDebug(): bool {
        return $this->debug;
    }

    public function getTransaction(): ?TransactionObject {
        return $this->transaction;
    }

    public function hasTransaction(): bool {
        return isset($this->transaction);
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

    public function flush(): void {
        $this->transaction->flush();
    }

    public function isRollbackOnly(): bool {
        return $this->transaction->isRollbackOnly();
    }

    public function hasSavepoint(): bool {
        return isset($this->savepoint);
    }

    public function createAndHoldSavepoint(): void {
        if (!$this->getTransaction()->isSavepointAllowed()) {
            throw new NestedTransactionNotSupportedException(
                "Cannot create a nested transaction because savepoints are not supported by your JDBC driver");
        }

        if ($this->getTransaction()->isRollbackOnly()) {
            throw new NestedTransactionNotSupportedException(
                "Cannot create savepoint for transaction which is already marked as rollback-only");
        }

        $this->savepoint = $this->getTransaction()->createSavepoint();
    }

    public function rollbackToHeldSavepoint(): void {
        if (isset($this->savepoint)) {
            $this->getTransaction()->rollbackToSavepoint($this->savepoint);
            $this->getTransaction()->releaseSavepoint($this->savepoint);
            $this->savepoint = null;
        }
        throw new TransactionUsageException(
            "Cannot roll back to savepoint - no savepoint associated with current transaction");
    }

    public function releaseHeldSavepoint(): void {
        if (isset($this->savepoint)) {
            $this->getTransaction()->releaseSavepoint($this->savepoint);
            $this->savepoint = null;
        }
        throw new TransactionUsageException(
            "Cannot release savepoint - no savepoint associated with current transaction");
    }

}
