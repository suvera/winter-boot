<?php
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ReflectionUtil;

final class BeanProvider {
    private object $cached;

    public function __construct(
        private ClassResource $class,
        private ?MethodResource $method = null,
        private bool $proxyUsed = false,
        private array $names = [],
    ) {
    }

    public function isProxyUsed(): bool {
        return $this->proxyUsed;
    }

    public function hasCached(): bool {
        return isset($this->cached);
    }

    public function getCached(): object {
        return $this->cached;
    }

    public function setCached(object $cached): void {
        $this->cached = $cached;
    }

    public function getClass(): ClassResource {
        return $this->class;
    }

    public function getMethod(): ?MethodResource {
        return $this->method;
    }

    public function addNames(string ...$names): void {
        foreach ($names as $name) {
            $this->names[$name] = $name;
        }
    }

    public function getNames(): array {
        return $this->names;
    }

    public function hasNames(string ...$names): bool {
        foreach ($names as $name) {
            if (!isset($this->names[$name])) {
                return false;
            }
        }
        return true;
    }

    public function equals(BeanProvider $other): bool {
        return ($other->getClass() === $this->getClass() && $other->getMethod() === $this->getMethod());
    }

    public function toString(): string {
        if ($this->method) {
            return ReflectionUtil::getFqName($this->method);
        }
        return ReflectionUtil::getFqName($this->class);
    }
}