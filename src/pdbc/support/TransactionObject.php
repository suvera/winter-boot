<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\Connection;
use dev\winterframework\txn\SavepointManager;

abstract class TransactionObject implements SavepointManager {

    private ?int $previousIsolationLevel = null;
    private bool $readOnly = false;
    private bool $savepointAllowed = false;

    public function __construct(
        private Connection $connection
    ) {
    }

    public abstract function isRollbackOnly(): bool;

    public abstract function flush(): void;

    public function getPreviousIsolationLevel(): ?int {
        return $this->previousIsolationLevel;
    }

    public function setPreviousIsolationLevel(?int $previousIsolationLevel): void {
        $this->previousIsolationLevel = $previousIsolationLevel;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    public function isSavepointAllowed(): bool {
        return $this->savepointAllowed;
    }

    public function setSavepointAllowed(bool $savepointAllowed): void {
        $this->savepointAllowed = $savepointAllowed;
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function setConnection(Connection $connection): void {
        $this->connection = $connection;
    }

}