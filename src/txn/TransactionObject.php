<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface TransactionObject extends SavepointManager {

    public function isRollbackOnly(): bool;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function flush(): void;

    public function getPreviousIsolationLevel(): ?int;

    public function isCommitted(): bool;

    public function isSuspended(): bool;

    public function suspend(): void;

    public function resume(): void;

    public function isReadOnly(): bool;

    public function isSavepointAllowed(): bool;
}