<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc;

use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;

interface ResultSet {
    const FETCH_FORWARD = 1000;
    const FETCH_REVERSE = 1001;
    const FETCH_UNKNOWN = 1002;
    const TYPE_FORWARD_ONLY = 1003;
    const TYPE_SCROLL_INSENSITIVE = 1004;
    const TYPE_SCROLL_SENSITIVE = 1005;
    const CONCUR_READ_ONLY = 1007;
    const CONCUR_UPDATABLE = 1008;
    const HOLD_CURSORS_OVER_COMMIT = 1;
    const CLOSE_CURSORS_AT_COMMIT = 2;

    public function next(): bool;

    public function first(): bool;

    public function close(): void;

    public function getType(): int;

    public function previous(): bool;

    public function isClosed(): bool;

    public function getStatement(): Statement|PreparedStatement|CallableStatement;

    public function getCursorName(): string;

    public function getColumns(): array;

    public function findColumn(string $column): int;

    public function isFirst(): bool;

    public function isLast(): bool;

    /**
     * Moves the cursor to the last row in this ResultSet object.
     *
     * @return bool
     */
    public function last(): bool;

    /**
     * Retrieves the current row
     *
     * @return int
     */
    public function getRow(): ?array;

    /**
     * Moves the cursor to the given row number in this ResultSet object.
     *
     * @param int $idx
     * @return bool
     */
    public function absolute(int $idx): bool;

    /**
     * Moves the cursor a relative number of rows, either positive or negative.
     *
     * @param int $idx
     * @return bool
     */
    public function relative(int $idx): bool;


    public function getObject(int|string $column, string $class): ?object;

    public function getBoolean(int|string $column): ?bool;

    public function getInt(string|int $column): ?int;

    public function getFloat(string|int $column): ?float;

    public function getArray(string|int $column): ?array;

    public function getTime(string|int $column): ?int;

    public function getDate(string|int $column): ?string;

    public function getBlob(string|int $column): ?Blob;

    public function getClob(string|int $column): ?Clob;

    public function getString(string|int $column): ?string;

}
