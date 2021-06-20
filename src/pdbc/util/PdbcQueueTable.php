<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\util;

class PdbcQueueTable {

    public function __construct(
        protected string $name,
        protected string $entity,
        protected string $idColumn,
        protected string $processedColumn,
        protected string $orderByColumn,
    ) {
    }

    public function getEntity(): string {
        return $this->entity;
    }

    public function setEntity(string $entity): void {
        $this->entity = $entity;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getIdColumn(): string {
        return $this->idColumn;
    }

    public function setIdColumn(string $idColumn): void {
        $this->idColumn = $idColumn;
    }

    public function getProcessedColumn(): string {
        return $this->processedColumn;
    }

    public function setProcessedColumn(string $processedColumn): void {
        $this->processedColumn = $processedColumn;
    }

    public function getOrderByColumn(): string {
        return $this->orderByColumn;
    }

    public function setOrderByColumn(string $orderByColumn): void {
        $this->orderByColumn = $orderByColumn;
    }

}