<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\ResultSet;
use dev\winterframework\pdbc\Statement;

abstract class AbstractStatement implements Statement {
    protected bool $closeOnCompletion = true;
    protected int $queryTimeout = 0;
    protected string $cursorName = '';
    protected int $fetchDirection;
    protected int $fetchSize;
    protected int $maxRows;
    protected array $sqlBatch = [];

    public function __construct(
        protected int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ) {
    }

    public function closeOnCompletion(bool $closeOnCompletion): void {
        $this->closeOnCompletion = $closeOnCompletion;
    }

    public function isCloseOnCompletion(): bool {
        return $this->closeOnCompletion;
    }

    public function getQueryTimeout(): int {
        return $this->queryTimeout;
    }

    public function setQueryTimeout(int $queryTimeout): void {
        $this->queryTimeout = $queryTimeout;
    }

    public function setCursorName(string $cursor): void {
        $this->cursorName = $cursor;
    }

    public function getResultSetType(): int {
        return $this->resultSetType;
    }

    public function setResultSetType(int $resultSetType): void {
        $this->resultSetType = $resultSetType;
    }

    public function setFetchDirection(int $fetchDirection): void {
        $this->fetchDirection = $fetchDirection;
    }

    public function getFetchDirection(): int {
        return $this->fetchDirection;
    }

    public function setFetchSize(int $fetchSize): void {
        $this->fetchSize = $fetchSize;
    }

    public function getFetchSize(): int {
        return $this->fetchSize;
    }

    public function setMaxRows(int $max): void {
        $this->maxRows = $max;
    }

    public function getMaxRows(): int {
        return $this->maxRows;
    }

    public function addBatch(string $sql): void {
        $this->sqlBatch[] = $sql;
    }

}