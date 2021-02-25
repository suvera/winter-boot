<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc;

use dev\winterframework\pdbc\support\DatabaseMetaData;
use dev\winterframework\txn\Savepoint;

interface Connection {
    const TRANSACTION_NONE = 0;
    const TRANSACTION_READ_UNCOMMITTED = 1;
    const TRANSACTION_READ_COMMITTED = 2;
    const TRANSACTION_REPEATABLE_READ = 4;
    const TRANSACTION_SERIALIZABLE = 8;

    public function setReadOnly(bool $readOnly): void;

    public function isReadOnly(): bool;

    public function close(): void;

    public function isClosed(): bool;

    public function createStatement(
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): Statement;

    public function prepareStatement(
        string $sql,
        int $autoGeneratedKeys = Statement::NO_GENERATED_KEYS,
        array $columnIdxOrNameOrs = [],
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): PreparedStatement;

    public function prepareCall(
        string $sql,
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): CallableStatement;

    public function getMetaData(): DatabaseMetaData;

    public function setHoldability(int $holdability): void;

    public function getHoldability(): int;

    public function setNetworkTimeout(int $timeout): void;

    public function getNetworkTimeout(): int;

    public function setAutoCommit(bool $autoCommit): void;

    public function getAutoCommit(): bool;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(Savepoint $savepoint = null): void;

    public function isSavepointAllowed(): bool;

    public function setSavepoint(string $name = null): Savepoint;

    public function releaseSavepoint(Savepoint $savepoint): void;

    public function setTransactionIsolation(int $level): void;

    public function getTransactionIsolation(): int;

    public function setClientInfo(array $keyPair): void;

    public function setClientInfoValue(string $name, string $value): void;

    public function getClientInfo(): array;

    public function getClientInfoValue(string $name): string;

    public function setSchema(string $schema): void;

    public function getSchema(): string;

}
