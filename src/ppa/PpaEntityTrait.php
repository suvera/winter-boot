<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

trait PpaEntityTrait {
    protected array $nullProperties = [];
    protected bool $__saved = false;
    protected bool $__creatable = true;
    protected bool $__updatable = true;

    public function isCreatable(): bool {
        return $this->__creatable;
    }

    public function isUpdatable(): bool {
        return $this->__updatable;
    }

    public function isStored(): bool {
        return $this->__saved;
    }

    public function setStored(bool $saved): void {
        $this->__saved = $saved;
    }

    public function setNullValue(string $propName): void {
        $this->nullProperties[$propName] = true;
    }

    public function hasNullValue(string $propName): bool {
        return isset($this->nullProperties[$propName]);
    }

    public function clearNullValue(string $propName): void {
        unset($this->nullProperties[$propName]);
    }

    public function clearNullValues(): void {
        $this->nullProperties = [];
    }
}