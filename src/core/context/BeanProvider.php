<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ReflectionUtil;

final class BeanProvider {
    private object $cached;
    private array $methodArgs = [];
    private ?object $providerObject = null;
    private ?string $initMethod = null;
    private ?string $destroyMethod = null;

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

    public function getInitMethod(): ?string {
        return $this->initMethod;
    }

    public function hasInitMethod(): bool {
        return isset($this->initMethod) && strlen($this->initMethod) > 0;
    }

    public function setInitMethod(?string $initMethod): void {
        $this->initMethod = $initMethod;
    }

    public function getDestroyMethod(): ?string {
        return $this->destroyMethod;
    }

    public function hasDestroyMethod(): bool {
        return isset($this->destroyMethod) && strlen($this->destroyMethod) > 0;
    }

    public function setDestroyMethod(?string $destroyMethod): void {
        $this->destroyMethod = $destroyMethod;
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

    public function hasMethodArgs(): bool {
        return !empty($this->methodArgs);
    }

    public function getMethodArgs(): array {
        return $this->methodArgs;
    }

    public function setMethodArgs(array $methodArgs): void {
        $this->methodArgs = $methodArgs;
    }

    public function hasProviderObject(): bool {
        return isset($this->providerObject);
    }

    public function getProviderObject(): ?object {
        return $this->providerObject;
    }

    public function setProviderObject(?object $providerObject): void {
        $this->providerObject = $providerObject;
    }

    public function equals(BeanProvider $other): bool {
        return ($other->getClass() === $this->getClass() && $other->getMethod() === $this->getMethod());
    }

    public function equals2(BeanProvider $other): bool {
        $classEquals = ($other->getClass()->getClass()->getName() === $this->getClass()->getClass()->getName());

        if (!$classEquals) {
            return false;
        }

        if ($other->getMethod() == null && $this->getMethod() == null) {
            return true;
        } else if ($other->getMethod() == null && $this->getMethod() != null) {
            return false;
        } else if ($other->getMethod() != null && $this->getMethod() == null) {
            return false;
        }

        return ($other->getMethod()->getMethod()->getName() !== $this->getMethod()->getMethod()->getName());
    }

    public function toString(): string {
        if ($this->method) {
            return ReflectionUtil::getFqName($this->method);
        }
        return ReflectionUtil::getFqName($this->class);
    }
}