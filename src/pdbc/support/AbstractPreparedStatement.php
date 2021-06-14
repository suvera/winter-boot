<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use DateTimeInterface;
use dev\winterframework\pdbc\core\BindType;
use dev\winterframework\pdbc\core\BindVar;
use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVar;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\PreparedStatement;
use dev\winterframework\pdbc\ResultSet;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;

abstract class AbstractPreparedStatement implements PreparedStatement {
    protected bool $closeOnCompletion = true;
    protected int $queryTimeout = 0;
    protected string $cursorName = '';
    protected int $fetchDirection;
    protected int $fetchSize;
    protected int $maxRows;
    protected BindVars $parameters;
    protected OutBindVars $outParameters;
    protected array $outValues = [];

    public function __construct(
        protected int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ) {
        $this->parameters = new BindVars();
        $this->outParameters = new OutBindVars();
    }

    public function clearParameters(): void {
        $this->parameters->clear();
        $this->outParameters->clear();
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

    public function bindValue(int|string $bind, mixed $value): void {
        if (is_int($value)) {
            $this->setInt($bind, $value);
        } else if (is_float($value)) {
            $this->setFloat($bind, $value);
        } else if (is_bool($value)) {
            $this->setBoolean($bind, $value);
        } else if ($value instanceof DateTimeInterface) {
            $this->setDate($bind, $value);
        } else if ($value instanceof Blob) {
            $this->setBlob($bind, $value);
        } else if ($value instanceof Clob) {
            $this->setClob($bind, $value);
        } else if (is_null($value)) {
            $this->setNull($bind);
        } else {
            $this->setString($bind, $value);
        }
    }

    /**
     * -----------------------------------
     * Setters for Parameters
     *
     * @param int|string $bind
     * @param bool $value
     */
    public function setBoolean(int|string $bind, bool $value): void {
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::BOOL);
    }

    public function setInt(int|string $bind, int $value): void {
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::INTEGER);
    }

    public function setFloat(int|string $bind, float $value): void {
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::FLOAT);
    }

    public function setNull(int|string $bind, int $sqlType = null): void {
        $this->parameters[$bind] = BindVar::of($bind, null, BindType::NULL);
    }

    public function setString(int|string $bind, string $value): void {
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::STRING);
    }

    public function setDate(int|string $bind, DateTimeInterface|int|string $value): void {
        if ($value instanceof DateTimeInterface) {
            $date = $value->format('Y-m-d H:i:s');
        } else if (is_numeric($value)) {
            $date = gmdate('Y-m-d H:i:s', $value);
        } else {
            $date = $value;
        }
        $this->parameters[$bind] = BindVar::of($bind, $date, BindType::DATE);
    }

    public function setBlob(int|string $bind, Blob $value): void {
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::BLOB);
    }

    public function setClob(int|string $bind, Clob $value): void {
        $this->parameters[$bind] = BindVar::of($bind, $value, BindType::CLOB);
    }

    public function bindVar(BindVar $bindVar): void {
        $this->parameters[] = $bindVar;
    }

    public function bindVars(BindVars $bindVars): void {
        $this->parameters->merge($bindVars);
    }

    public function outBindVar(OutBindVar $bindVar): void {
        $this->outParameters[] = $bindVar;
    }

    public function outBindVars(OutBindVars $bindVars): void {
        $this->outParameters->merge($bindVars);
    }

    public function getOutValues(): array {
        return $this->outValues;
    }

}