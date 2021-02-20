<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\txn\TransactionDefinition;

class DefaultTransactionDefinition implements TransactionDefinition {
    private int $timeout = -1;
    private bool $readOnly = false;
    private string $name;

    public function __construct(
        private int $propagationBehavior = 0,
        private int $isolationLevel = -1,
    ) {
    }

    public function getTimeout(): int {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): void {
        $this->timeout = $timeout;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getPropagationBehavior(): int {
        return $this->propagationBehavior;
    }

    public function setPropagationBehavior(int $propagationBehavior): void {
        $this->propagationBehavior = $propagationBehavior;
    }

    public function getIsolationLevel(): int {
        return $this->isolationLevel;
    }

    public function setIsolationLevel(int $isolationLevel): void {
        $this->isolationLevel = $isolationLevel;
    }

    public function __toString(): string {
        return json_encode((array)$this);
    }
    
}
