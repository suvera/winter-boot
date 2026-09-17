<?php

declare(strict_types=1);

namespace dev\winterframework\web\session;

/**
 * Per-request session data bag.
 *
 * Holds data ONLY in object properties. Never touches superglobals and
 * never calls any native session function, so concurrent
 * Swoole coroutines sharing a worker process cannot contaminate each
 * other's session state. Each request gets its own instance via
 * SessionManager::open(); the instance is garbage once the request ends.
 */
class RequestSession {
    private bool $destroyed = false;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private string $id,
        private array $data = [],
        private bool $new = true,
        private string $username = '',
        private int $sessionType = 0
    ) {
    }

    public function getId(): string {
        return $this->id;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function setUsername(string $username): void {
        $this->username = $username;
    }

    public function getSessionType(): int {
        return $this->sessionType;
    }

    public function setSessionType(int $sessionType): void {
        $this->sessionType = $sessionType;
    }

    public function isNew(): bool {
        return $this->new;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void {
        unset($this->data[$key]);
    }

    public function destroy(): void {
        $this->destroyed = true;
        $this->data = [];
    }

    public function isDestroyed(): bool {
        return $this->destroyed;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAll(): array {
        return $this->data;
    }
}
