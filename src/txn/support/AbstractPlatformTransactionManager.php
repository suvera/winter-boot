<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\txn\PlatformTransactionManager;

abstract class AbstractPlatformTransactionManager implements PlatformTransactionManager {
    const SYNCHRONIZATION_ALWAYS = 0;
    const SYNCHRONIZATION_ON_ACTUAL_TRANSACTION = 1;
    const SYNCHRONIZATION_NEVER = 2;
    private int $transactionSynchronization = self::SYNCHRONIZATION_ALWAYS;
    private int $defaultTimeout;
    private bool $nestedTransactionAllowed = false;
    private bool $validateExistingTransaction = true;
    private bool $globalRollbackOnParticipationFailure = false;
    private bool $failEarlyOnGlobalRollbackOnly = false;
    private bool $rollbackOnCommitFailure = false;

    public function __construct() {
    }

    public function getTransactionSynchronization(): int {
        return $this->transactionSynchronization;
    }

    public function setTransactionSynchronization(int $transactionSynchronization): void {
        $this->transactionSynchronization = $transactionSynchronization;
    }

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

    public function isGlobalRollbackOnParticipationFailure(): bool {
        return $this->globalRollbackOnParticipationFailure;
    }

    public function setGlobalRollbackOnParticipationFailure(bool $globalRollbackOnParticipationFailure): void {
        $this->globalRollbackOnParticipationFailure = $globalRollbackOnParticipationFailure;
    }

    public function isFailEarlyOnGlobalRollbackOnly(): bool {
        return $this->failEarlyOnGlobalRollbackOnly;
    }

    public function setFailEarlyOnGlobalRollbackOnly(bool $failEarlyOnGlobalRollbackOnly): void {
        $this->failEarlyOnGlobalRollbackOnly = $failEarlyOnGlobalRollbackOnly;
    }

    public function isRollbackOnCommitFailure(): bool {
        return $this->rollbackOnCommitFailure;
    }

    public function setRollbackOnCommitFailure(bool $rollbackOnCommitFailure): void {
        $this->rollbackOnCommitFailure = $rollbackOnCommitFailure;
    }

}
