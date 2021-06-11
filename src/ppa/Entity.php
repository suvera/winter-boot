<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

use dev\winterframework\stereotype\ppa\Column;
use dev\winterframework\stereotype\ppa\SequenceGenerator;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\stereotype\ppa\TableGenerator;

class Entity {

    protected string $ppaClass;
    protected Table $table;

    protected array $columns = [];
    protected array $columnMap = [];
    protected array $idColumns = [];

    protected array $insertColumns = [];
    protected array $updateColumns = [];

    /**
     * @var SequenceGenerator[]
     */
    protected array $sequenceGenerators = [];

    /**
     * @var TableGenerator[]
     */
    protected array $tableGenerators = [];

    public function getPpaClass(): string {
        return $this->ppaClass;
    }

    public function setPpaClass(string $ppaClass): Entity {
        $this->ppaClass = $ppaClass;
        return $this;
    }

    public function getTable(): Table {
        return $this->table;
    }

    public function setTable(Table $table): Entity {
        $this->table = $table;
        return $this;
    }

    /**
     * @return Column[]
     */
    public function getColumns(): array {
        return $this->columns;
    }

    public function addColumn(Column $column): Entity {
        $this->columns[$column->getVarName()] = $column;
        $this->columnMap[$column->getName()] = $column->getVarName();

        if ($column->isId()) {
            $this->idColumns[$column->getVarName()] = $column;
        }

        if ($column->isUpdatable()) {
            $this->updateColumns[$column->getVarName()] = $column;
        }

        if ($column->isInsertable()) {
            $this->insertColumns[$column->getVarName()] = $column;
        }
        return $this;
    }

    public function setColumns(array $columns): Entity {
        foreach ($columns as $column) {
            $this->addColumn($column);
        }
        return $this;
    }

    public function hasId(): bool {
        return count($this->idColumns) > 0;
    }

    public function getColumnMap(): array {
        return $this->columnMap;
    }

    /**
     * @return Column[]
     */
    public function getIdColumns(): array {
        return $this->idColumns;
    }

    /**
     * @return Column[]
     */
    public function getInsertColumns(): array {
        return $this->insertColumns;
    }

    /**
     * @return Column[]
     */
    public function getUpdateColumns(): array {
        return $this->updateColumns;
    }

    /**
     * @return SequenceGenerator[]
     */
    public function getSequenceGenerators(): array {
        return $this->sequenceGenerators;
    }

    public function hasSequenceGenerator(string $propName): bool {
        return isset($this->sequenceGenerators[$propName]);
    }

    public function getSequenceGenerator(string $propName): ?SequenceGenerator {
        return $this->sequenceGenerators[$propName] ?? null;
    }

    public function addSequenceGenerator(SequenceGenerator $seq): void {
        $this->sequenceGenerators[$seq->getVarName()] = $seq;
    }

    /**
     * @return TableGenerator[]
     */
    public function getTableGenerators(): array {
        return $this->tableGenerators;
    }

    public function hasTableGenerator(string $propName): bool {
        return isset($this->tableGenerators[$propName]);
    }

    public function getTableGenerator(string $propName): ?TableGenerator {
        return $this->tableGenerators[$propName] ?? null;
    }

    public function addTableGenerator(TableGenerator $tab): void {
        $this->tableGenerators[$tab->getVarName()] = $tab;
    }

}