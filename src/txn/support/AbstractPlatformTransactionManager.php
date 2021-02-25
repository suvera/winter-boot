<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\pdbc\ex\SQLException;
use dev\winterframework\txn\ex\IllegalTransactionStateException;
use dev\winterframework\txn\ex\NestedTransactionNotSupportedException;
use dev\winterframework\txn\ex\TransactionException;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\Transaction;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

abstract class AbstractPlatformTransactionManager implements PlatformTransactionManager, Transaction {
    use Wlf4p;

    protected int $defaultTimeout;
    protected bool $nestedTransactionAllowed = false;
    protected bool $validateExistingTransaction = true;
    protected bool $rollbackOnCommitFailure = false;
    protected TransactionsHolder $txnStack;

    public function __construct() {
        $this->txnStack = new TransactionsHolder();
    }

    /**
     * ----------------------------------------------------------------
     * Implement generic methods
     *
     * @param TransactionDefinition $definition
     * @return TransactionStatus
     */
    public function getTransaction(TransactionDefinition $definition): TransactionStatus {
        if ($this->hasExistingTransaction()) {
            // Existing transaction found -> check propagation behavior to find out how to behave.
            return $this->handleExistingTransaction($definition);
        }

        if ($definition->getTimeout() < TransactionDefinition::TIMEOUT_DEFAULT) {
            throw new SQLException("Invalid transaction timeout " . $definition->getTimeout());
        }

        // No existing transaction found -> check propagation behavior to find out how to proceed.
        if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_MANDATORY) {
            throw new IllegalTransactionStateException(
                "No existing transaction found for transaction marked with propagation 'mandatory'");
        } else if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_REQUIRED ||
            $definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_REQUIRES_NEW ||
            $definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_NESTED) {

            self::logDebug("Creating new transaction with name ["
                . $definition->getName() . "]: ");

            $txn = $this->doGetTransaction($definition);
            $this->startTransaction($txn);
            $this->txnStack->push($txn);
            return $txn;
        } else {
            // PROPAGATION_NOT_SUPPORTED:
            // Create "empty" transaction: no actual transaction, but potentially synchronization.
            return $this->prepareNoTransaction($definition);
        }
    }

    public function rollback(TransactionStatus $status): void {
        if ($status->isCompleted()) {
            throw new IllegalTransactionStateException(
                "Transaction is already completed - do not call commit or "
                . "rollback more than once per transaction");
        }
        $this->processRollback($status);
    }

    public function commit(TransactionStatus $status): void {
        if ($status->isCompleted()) {
            throw new IllegalTransactionStateException(
                "Transaction is already completed - do not "
                . "call commit or rollback more than once per transaction");
        }


        if ($status->isRollbackOnly()) {
            self::logDebug("Transactional code has requested rollback");
            $this->processRollback($status);
            return;
        }

        $this->processCommit($status);
    }

    /**
     * -------------------------------------------------------     *
     * Private Methods
     *
     * @noinspection PhpUnusedParameterInspection
     * @param TransactionDefinition $definition
     * @return TransactionStatus
     */
    protected function prepareNoTransaction(TransactionDefinition $definition): TransactionStatus {
        return new NoTransactionStatus();
    }

    protected function processCommit(TransactionStatus $status): void {
        try {

            $this->prepareForCommit($status);

            if ($status->hasSavepoint()) {
                self::logDebug("processCommit() - Releasing transaction savepoint");
                $status->releaseHeldSavepoint();

            } else if ($status->isNewTransaction()) {
                self::logDebug("processCommit() - Initiating transaction commit");
                $this->doCommit($status);
                self::logDebug("processCommit() - Commit done!");
            } else {
                if ($status->hasTransaction()) {
                    if ($status->isRollbackOnly()) {
                        self::logDebug("processCommit() - Participating transaction failed - "
                            . "marking existing transaction as rollback-only");
                    }
                } else {
                    self::logDebug("processCommit() - Should roll back transaction but cannot - "
                        . "no transaction available");
                }
            }
        } catch (Throwable $e) {
            throw new TransactionException('', 0, $e);
        } finally {
            $this->doCleanupAfterCompletion($status);
        }
    }

    protected function processRollback(TransactionStatus $status): void {
        try {

            if ($status->hasSavepoint()) {
                self::logDebug("processRollback() - Rolling back transaction to savepoint");
                $status->rollbackToHeldSavepoint();
            } else if ($status->isNewTransaction()) {
                self::logDebug("processRollback() - Initiating transaction rollback");
                $this->doRollback($status);
                self::logDebug("processRollback() - Rollback done!");
            } else {
                if ($status->hasTransaction()) {
                    if ($status->isRollbackOnly()) {
                        self::logDebug("processRollback() - Participating transaction failed - "
                            . "marking existing transaction as rollback-only");
                    }
                } else {
                    self::logDebug("processRollback() - Should roll back transaction but cannot - "
                        . "no transaction available");
                }
            }
        } catch (Throwable $e) {
            throw new TransactionException('', 0, $e);
        } finally {
            $this->doCleanupAfterCompletion($status);
        }
    }

    protected function handleExistingTransaction(TransactionDefinition $definition): TransactionStatus {
        $currentStatus = $this->txnStack->current();

        if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_NEVER) {
            throw new IllegalTransactionStateException(
                "Existing transaction found for transaction marked with propagation 'never'");
        }

        if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_NOT_SUPPORTED) {
            return $this->prepareNoTransaction($definition);
        }

        if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_REQUIRES_NEW) {
            self::LogDebug("Suspending current transaction, creating new transaction with name ["
                . $definition->getName() . "]");

            $currentStatus->getTransaction()->suspend();

            $status = $this->doGetTransaction($definition);
            $this->startTransaction($status);
            $this->txnStack->push($status);
            return $status;
        }

        if ($definition->getPropagationBehavior() == TransactionDefinition::PROPAGATION_NESTED) {
            if (!$this->isNestedTransactionAllowed()) {
                throw new NestedTransactionNotSupportedException(
                    "Transaction manager does not allow nested transactions by default - "
                    . "specify 'nestedTransactionAllowed' property with value 'true'");
            }
            self::LogDebug("Creating nested transaction with name [" . $definition->getName() . "]");

            $status = $this->doGetTransaction($definition);
            if ($this->useSavepointForNestedTransaction()) {
                // Create savepoint within existing managed transaction,
                $status->createAndHoldSavepoint();
            }
            $this->startTransaction($status);
            $this->txnStack->push($status);
            return $status;
        }

        $currentStatus->getTransaction()->resume();

        return $currentStatus;
    }

    /**
     * -----------------------------------------------------------------
     * Child class must implement these methods
     *
     * @param TransactionDefinition $definition
     * @return TransactionStatus
     */
    protected abstract function doGetTransaction(TransactionDefinition $definition): TransactionStatus;

    protected function startTransaction(TransactionStatus $txn): void {
        $txn->getTransaction()->begin();
    }

    protected function hasExistingTransaction(): bool {
        return !$this->txnStack->isEmpty();
    }

    protected function prepareForCommit(TransactionStatus $status): void {
    }

    protected abstract function doCommit(TransactionStatus $status): void;

    protected abstract function doRollback(TransactionStatus $status): void;

    protected function doCleanupAfterCompletion(TransactionStatus $status): void {
        $this->txnStack->remove($status);
    }

    protected function useSavepointForNestedTransaction(): bool {
        return true;
    }

    /**
     * ------------------------------------------------------------
     * SETTER & GETTERS
     *
     * @return int
     */
    public function getDefaultTimeout(): int {
        return $this->defaultTimeout;
    }

    public function setDefaultTimeout(int $defaultTimeout): void {
        $this->defaultTimeout = $defaultTimeout;
    }

    public function isNestedTransactionAllowed(): bool {
        return $this->nestedTransactionAllowed;
    }

    public function setNestedTransactionAllowed(bool $nestedTransactionAllowed): void {
        $this->nestedTransactionAllowed = $nestedTransactionAllowed;
    }

    public function isValidateExistingTransaction(): bool {
        return $this->validateExistingTransaction;
    }

    public function setValidateExistingTransaction(bool $validateExistingTransaction): void {
        $this->validateExistingTransaction = $validateExistingTransaction;
    }

    public function isRollbackOnCommitFailure(): bool {
        return $this->rollbackOnCommitFailure;
    }

    public function setRollbackOnCommitFailure(bool $rollbackOnCommitFailure): void {
        $this->rollbackOnCommitFailure = $rollbackOnCommitFailure;
    }


}
