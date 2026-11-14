<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

/**
 * The open sessions of this worker, by Swoole fd (how frames arrive) and by
 * session id (how applications refer to them). Bounded by the worker's open
 * connections: entries are removed when a connection closes.
 *
 * @internal Applications read sessions through {@see \dev\winterframework\web\websocket\WebSocketSessions}.
 */
final class WebSocketSessionRegistry {
    /** @var array<int, DefaultWebSocketSession> */
    private array $byFd = [];
    /** @var array<string, DefaultWebSocketSession> */
    private array $byId = [];

    public function add(DefaultWebSocketSession $session): void {
        $this->byFd[$session->getFd()] = $session;
        $this->byId[$session->getId()] = $session;
    }

    public function remove(int $fd): ?DefaultWebSocketSession {
        $session = $this->byFd[$fd] ?? null;
        if ($session !== null) {
            unset($this->byFd[$fd], $this->byId[$session->getId()]);
        }
        return $session;
    }

    public function byFd(int $fd): ?DefaultWebSocketSession {
        return $this->byFd[$fd] ?? null;
    }

    public function byId(string $id): ?DefaultWebSocketSession {
        return $this->byId[$id] ?? null;
    }

    /** @return DefaultWebSocketSession[] */
    public function all(): array {
        return array_values($this->byFd);
    }

    public function count(): int {
        return count($this->byFd);
    }
}
