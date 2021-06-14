<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\ex\SQLException;
use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\pdbc\support\AbstractResultSet;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;
use dev\winterframework\type\TypeCast;
use dev\winterframework\util\log\Wlf4p;
use PDO;

class OciResultSet extends AbstractResultSet {
    use Wlf4p;

    private int $type;
    private array|bool $row = false;
    private array $columns = [];

    public function __construct(
        private OciQueryStatement|OciPreparedStatement|OciCallableStatement $ociStmt,
        private string $cursorName = ''
    ) {
        $this->type = PDO::CURSOR_FWDONLY;

        if ($this->ociStmt->getStatement() != null) {
            $this->findColumns();
        }
    }

    public function __destruct() {
        $this->close();
    }

    public function getStatement(): OciQueryStatement|OciPreparedStatement|OciCallableStatement {
        return $this->ociStmt;
    }

    public function close(): void {
        if (!$this->ociStmt->isClosed()) {
            $this->ociStmt->close();
        }
    }

    public function isClosed(): bool {
        return $this->ociStmt->isClosed();
    }

    public function getType(): int {
        return $this->type;
    }

    public function getCursorName(): string {
        return $this->cursorName;
    }

    /**
     * ----------
     * Cursor Movement methods
     *
     * @return bool
     */
    public function next(): bool {
        if ($this->ociStmt->getStatement() == null) {
            return false;
        }

        $this->getStatement()->getConnection()->touch();
        $this->row = oci_fetch_array($this->ociStmt->getStatement(), OCI_BOTH + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        return !empty($this->row);
    }

    public function previous(): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
    }

    public function first(): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
    }

    public function last(): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
    }

    public function absolute(int $idx): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
    }

    public function relative(int $idx): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
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
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
    }

    /**
     * Note: Calling the method isLast may be expensive because the driver might need to fetch ahead one row
     *   in order to determine whether the current row is the last row in the result set.
     * @return bool
     */
    public function isLast(): bool {
        throw new SQLFeatureNotSupportedException('PDO driver does not support this method ' . __METHOD__);
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
        return $this->row ?? null;
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
        $len = oci_num_fields($this->ociStmt->getStatement());
        for ($i = 1; $i <= $len; $i++) {
            $fieldName = oci_field_name($this->ociStmt->getStatement(), $i);
            $this->columns[$fieldName] = $i - 1;
        }
    }

}