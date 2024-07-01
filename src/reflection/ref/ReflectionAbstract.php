<?php

declare(strict_types=1);

namespace dev\winterframework\reflection\ref;

use Serializable;

abstract class ReflectionAbstract implements Serializable {
    protected array $_data = [];
    protected mixed $delegate;

    public function serialize(): string {
        return serialize($this->_data);
    }

    public function unserialize($serialized): void {
        $this->_data = unserialize($serialized);
    }

    public function __serialize(): array {
        return $this->_data;
    }

    public function __unserialize(array $data): void {
        $this->_data = $data;
    }

    /**
     * @return mixed
     */
    public function getDelegate(): mixed {
        if (!isset($this->delegate)) {
            $this->delegate = $this->loadDelegate();
        }
        return $this->delegate;
    }

    public function __call(string $name, mixed $arguments): mixed {
        $this->getDelegate();
        if ($this->delegate instanceof \ReflectionProperty && $name === 'setValue' && count($arguments) == 1) {
            return $this->delegate->getDeclaringClass()->setStaticPropertyValue($this->delegate->getName(), $arguments[0]);
        } else {
            return $this->delegate->$name(...$arguments);
        }
    }

    protected abstract function loadDelegate(): mixed;
}
