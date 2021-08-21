<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\util;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\ResultSet;
use dev\winterframework\ppa\PpaEntity;
use dev\winterframework\ppa\PpaObjectMapperFactory;
use dev\winterframework\type\Queue;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use RuntimeException;
use Throwable;

class PdbcQueue implements Queue {
    use Wlf4p;

    protected array $cache = [];

    public function __construct(
        protected PdbcTemplate $pdbc,
        protected PdbcQueueTable $table,
        protected string $extraFilter = '',
        protected array $extraBinds = []
    ) {
    }

    public function add(mixed $item, int $timeoutMs = 0): bool {
        TypeAssert::objectOf($item, PpaEntity::class);

        $this->pdbc->updateObjects($item);
        return true;
    }

    public function poll(int $timeoutMs = 0): ?object {

        $sql = 'select * from ' . $this->table->getName()
            . ' where '
            . $this->table->getProcessedColumn() . ' is null ';
        $binds = [];

        if ($this->extraFilter) {
            $sql .= ' and (' . $this->extraFilter . ') ';
            $binds = array_merge($binds, $this->extraBinds);
        }
        $sql .= ' order by ' . $this->table->getOrderByColumn();

        $ps_id = substr(getmypid() . '-' . gethostname(), 0, 100);
        $record = null;

        $updateStmt = 'update ' . $this->table->getName() . ' set '
            . $this->table->getProcessedColumn() . ' = :ps_id '
            . ' where ' . $this->table->getProcessedColumn() . ' is null '
            . ' and ' . $this->table->getIdColumn() . ' = :id ';

        try {
            $this->pdbc->query(
                $sql,
                $binds,
                function (ResultSet $rs) use (&$record, $ps_id, $updateStmt) {
                    $id = $rs->getString($this->table->getIdColumn());
                    $updated = $this->pdbc->update(
                        $updateStmt,
                        ['id' => $id, 'ps_id' => $ps_id]
                    );
                    if ($updated) {
                        $record = $rs->getRow();
                        throw new RuntimeException('got result');
                    }
                }
            );
        } catch (Throwable $e) {
            self::logDebug($e->getMessage());
        }

        if ($record) {
            $entity = PpaObjectMapperFactory::getMapper('generic')
                ->createObject($this->table->getEntity(), $record);

            $this->pdbc->deleteObjects($entity);

            return $entity;
        }

        return null;
    }

    public function isUnbounded(): bool {
        return true;
    }

    public function size(): int {
        $sql = 'select count(1) from ' . $this->table->getName()
            . ' where '
            . $this->table->getProcessedColumn() . ' is null ';
        $binds = [];

        if ($this->extraFilter) {
            $sql .= ' and (' . $this->extraFilter . ') ';
            $binds = array_merge($binds, $this->extraBinds);
        }

        return $this->pdbc->queryForScalar($sql, $binds);
    }

    public function isCountable(): bool {
        return true;
    }

}