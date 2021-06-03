<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\Connection;
use dev\winterframework\pdbc\ResultSet;

abstract class AbstractConnection implements Connection {
    private bool $readOnly = false;
    private int $holdability = ResultSet::CLOSE_CURSORS_AT_COMMIT;
    private bool $autoCommit = true;
    private int $networkTimeout = 0;
    private int $transactionIsolation = Connection::TRANSACTION_NONE;

    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function getHoldability(): int {
        return $this->holdability;
    }

    public function setHoldability(int $holdability): void {
        $this->holdability = $holdability;
    }

    public function getAutoCommit(): bool {
        return $this->autoCommit;
    }

    public function setAutoCommit(bool $autoCommit): void {
        $this->autoCommit = $autoCommit;
    }

    public function getNetworkTimeout(): int {
        return $this->networkTimeout;
    }

    public function setNetworkTimeout(int $timeout): void {
        $this->networkTimeout = $timeout;
    }

    public function getTransactionIsolation(): int {
        return $this->transactionIsolation;
    }

    public function setTransactionIsolation(int $level): void {
        $this->transactionIsolation = $level;
    }

}