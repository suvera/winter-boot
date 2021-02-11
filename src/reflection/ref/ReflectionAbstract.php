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
        return $this->delegate->$name(...$arguments);
    }

    protected abstract function loadDelegate(): mixed;
}