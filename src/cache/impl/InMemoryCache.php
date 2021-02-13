<?php

declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\ValueRetrievalException;
use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\core\System;
use dev\winterframework\exception\IllegalStateException;
use Throwable;

class InMemoryCache implements Cache {
    /**
     * @var SimpleValueWrapper[]
     */
    private array $items = [];

    private string $name;
    private CacheConfiguration $config;

    public function __construct(
        string $name,
        ?CacheConfiguration $config = null
    ) {
        $this->name = $name;
        $this->config = (!is_null($config) ? $config : new CacheConfiguration());
    }

    public function clear(): void {
        $this->items = [];
    }

    public function evict(string $key): bool {
        if (isset($this->items[$key])) {
            unset($this->items[$key]);
            return true;
        }
        return false;
    }

    public function has(string $key): bool {
        if (isset($this->items[$key])) {
            $ms = System::currentTimeMillis();

            $exist = (
                    $this->config->expireAfterWriteMs <= 0
                    || ($this->items[$key]->getWriteTimeMs() + $this->config->expireAfterWriteMs) > $ms
                ) && (
                    $this->config->expireAfterAccessMs <= 0
                    || ($this->items[$key]->getAccessTimeMs() + $this->config->expireAfterAccessMs) < $ms
                );

            if (!$exist) {
                unset($this->items[$key]);
            }
            return $exist;
        }
        return false;
    }

    public function get(string $key): ValueWrapper {
        if ($this->has($key)) {
            return $this->items[$key];
        } else if (isset($this->items[$key])) {
            unset($this->items[$key]);
        }
        return SimpleValueWrapper::$NULL_VALUE;
    }

    public function getOrProvide(string $key, callable $valueProvider): ValueWrapper {
        if ($this->has($key)) {
            return $this->items[$key];
        }
        try {
            $value = $valueProvider();
        } catch (Throwable $e) {
            throw new ValueRetrievalException('Provider to cache value is failed for "'
                . $key . '"', 0, $e
            );
        }
        $this->items[$key] = new SimpleValueWrapper($value);
        return $this->items[$key];
    }

    public function getAsType(string $key, string $class): ?object {
        $value = $this->get($key);
        if ($value->get() === null) {
            return null;
        }
        if ($value->get() instanceof $class) {
            return $value->get();
        } else {
            throw new IllegalStateException('value in cache is not of type "'
                . $class . '" for key "' . $key . '"'
            );
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function getNativeCache(): object {
        return $this;
    }

    public function invalidate(): bool {
        $this->clear();
        return true;
    }

    public function put(string $key, mixed $value): void {
        if (!isset($this->items[$key]) && count($this->items) >= $this->config->maximumSize) {
            array_shift($this->items);
        }

        $this->items[$key] = new SimpleValueWrapper($value instanceof ValueWrapper ? $value->get() : $value);
    }

    public function putIfAbsent(string $key, mixed $value): ValueWrapper {
        if (!$this->has($key)) {
            $this->put($key, $value);
        }

        return $this->items[$key];
    }

}
