<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\ex\SQLException;
use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\pdbc\support\AbstractResultSet;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;
use dev\winterframework\type\TypeCast;
use dev\winterframework\util\log\Wlf4p;
use PDO;
use PDOStatement;
use Throwable;

class PdoResultSet extends AbstractResultSet {
    use Wlf4p;

    private int $type;
    private array|bool $row = false;
    private array $columns = [];
    private ?PDOStatement $stmt;

    public function __construct(
        private PdoQueryStatement|PdoPreparedStatement|PdoCallableStatement $pdoStmt,
        private string $cursorName = ''
    ) {
        $this->stmt = $this->pdoStmt->getStatement();
        $type = PDO::CURSOR_FWDONLY;

        if ($this->stmt != null) {
            try {
                $type = $this->stmt->getAttribute(PDO::ATTR_CURSOR);
            } /** @noinspection PhpUnusedLocalVariableInspection */
            catch (Throwable $e) {
                //self::logException($e);
            }
        }

        if (!$type) {
            $type = PDO::CURSOR_FWDONLY;
        }

        $this->type = ($type == PDO::CURSOR_SCROLL) ? self::TYPE_SCROLL_SENSITIVE : self::TYPE_FORWARD_ONLY;

        if ($this->stmt != null) {
            $this->findColumns();
        }
    }

    public function getStatement(): PdoQueryStatement|PdoPreparedStatement|PdoCallableStatement {
        return $this->pdoStmt;
    }

    public function close(): void {
        $this->pdoStmt->close();
    }

    public function isClosed(): bool {
        return $this->pdoStmt->isClosed();
    }

    public function getType(): int {
        return $this->type;
    }

    public function getCursorName(): string {
        return $this->cursorName;
    }

    private function assertScrollableCursor(string $method = ''): void {
        $this->getStatement()->getConnection()->touch();
        if ($this->type == self::TYPE_FORWARD_ONLY) {
            throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . $method);
        }
    }

    /**
     * ----------
     * Cursor Movement methods
     *
     * @return bool
     */
    public function next(): bool {
        if (is_null($this->stmt)) {
            return false;
        }

        $this->getStatement()->getConnection()->touch();

        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_NEXT);
        return !empty($this->row);
    }

    public function previous(): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_PRIOR);
        return !empty($this->row);
    }

    public function first(): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_FIRST);
        return !empty($this->row);
    }

    public function last(): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_LAST);
        return !empty($this->row);
    }

    public function absolute(int $idx): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_ABS, $idx);
        return !empty($this->row);
    }

    public function relative(int $idx): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $this->row = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_REL, $idx);
        return !empty($this->row);
    }

    /**
     * --------------
     * Cursor position checking
     *
     * Note: Calling the method isFirst may be expensive because the driver might need to fetch ahead one row
     *   in order to determine whether the current row is the last row in the result set.
     *
     * @return bool
     */
    public function isFirst(): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        $val = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_PRIOR);
        if ($val === false) {
            return true;
        }
        // Reset back to original
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_NEXT);
        return false;
    }

    /**
     * Note: Calling the method isLast may be expensive because the driver might need to fetch ahead one row
     *   in order to determine whether the current row is the last row in the result set.
     * @return bool
     */
    public function isLast(): bool {
        if (is_null($this->stmt)) {
            return false;
        }
        $this->assertScrollableCursor(__METHOD__);
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $val = $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_NEXT);
        if ($val === false) {
            return true;
        }
        // Reset back to original
        $this->stmt->fetch(PDO::FETCH_BOTH, PDO::FETCH_ORI_PRIOR);
        return false;
    }

    /**
     * ---------------------
     * Column handling methods
     *
     * @return array
     */
    public function getColumns(): array {
        return array_keys($this->columns);
    }

    public function findColumn(string $column): int {
        if (isset($this->columns[$column])) {
            return $this->columns[$column];
        }
        throw new SQLException('columnLabel is invalid "' . $column . '"');
    }

    public function getRow(): ?array {
        return $this->row ?: null;
    }

    public function getObject(int|string $column, string $class): ?object {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return unserialize($this->row[$column], ['allowed_classes' => [$class]]);
        }
        return null;
    }

    public function getBoolean(int|string $column): ?bool {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return boolval($this->row[$column]);
        }
        return null;
    }

    public function getInt(int|string $column): ?int {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return intval($this->row[$column]);
        }
        return null;
    }

    public function getFloat(int|string $column): ?float {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return floatval($this->row[$column]);
        }
        return null;
    }

    public function getArray(int|string $column): ?array {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return json_decode($this->row[$column], true);
        }
        return null;
    }

    public function getTime(int|string $column): ?int {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return intval($this->row[$column]);
        }
        return null;
    }

    public function getDate(int|string $column): ?string {
        $this->assertColumnExist($column);
        return $this->row[$column];
    }

    public function getBlob(int|string $column): ?Blob {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return Blob::valueOf($this->row[$column]);
        }
        return null;
    }

    public function getClob(int|string $column): ?Clob {
        $this->assertColumnExist($column);
        if (isset($this->row[$column])) {
            return Clob::valueOf($this->row[$column]);
        }
        return null;
    }

    public function getString(int|string $column): ?string {
        $this->assertColumnExist($column);
        return TypeCast::toString($this->row[$column]);
    }

    private function assertColumnExist(int|string $column): void {
        if (is_bool($this->row)) {
            throw new SQLException('end of the result or no result found');
        }

        if (!array_key_exists($column, $this->row)) {
            throw new SQLException('column name/index is invalid ' . $column);
        }
    }

    private function findColumns(): void {
        $len = $this->stmt->columnCount();
        for ($i = 0; $i < $len; $i++) {
            $meta = $this->stmt->getColumnMeta($i);
            $this->columns[$meta['name']] = $i;
        }
    }

}