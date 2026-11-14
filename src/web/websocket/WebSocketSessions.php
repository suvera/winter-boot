<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

/**
 * The open WebSocket sessions of the current worker, for pushing from
 * elsewhere in the application (a service, a scheduled task, another
 * request): `#[Autowired] WebSocketSessions $sockets;`
 *
 * ```php
 * foreach ($this->sockets->findByAttribute('userId', $userId) as $session) {
 *     $session->send(['type' => 'notification', 'text' => $text]);
 * }
 * ```
 *
 * Only this worker's connections are visible: a user connected through
 * another worker or another pod is not. Applications that must reach every
 * connection publish the event (e.g. Redis pub/sub) and let each worker
 * deliver to its own sessions.
 */
class WebSocketSessions {
    /** @var array<int, WebSocketSession> Bounded by the open connections of this worker. */
    private array $sessions = [];

    public function get(int $id): ?WebSocketSession {
        return $this->sessions[$id] ?? null;
    }

    /**
     * @param string|null $handlerPath only sessions of that #[WebSocketController] path
     * @return WebSocketSession[]
     */
    public function all(?string $handlerPath = null): array {
        if ($handlerPath === null) {
            return array_values($this->sessions);
        }
        return array_values(array_filter(
            $this->sessions,
            fn(WebSocketSession $s): bool => $s->getHandlerPath() === $handlerPath
        ));
    }

    /**
     * Open sessions whose attribute $name equals $value (strict comparison).
     *
     * @return WebSocketSession[]
     */
    public function findByAttribute(string $name, mixed $value): array {
        $found = [];
        foreach ($this->sessions as $session) {
            if ($session->hasAttribute($name) && $session->getAttribute($name) === $value && $session->isOpen()) {
                $found[] = $session;
            }
        }
        return $found;
    }

    /**
     * Sends $data to every open session whose attribute matches; returns
     * how many accepted it.
     */
    public function sendToAttribute(string $name, mixed $value, string|array|\JsonSerializable $data): int {
        $sent = 0;
        foreach ($this->findByAttribute($name, $value) as $session) {
            if ($session->send($data)) {
                $sent++;
            }
        }
        return $sent;
    }

    public function count(): int {
        return count($this->sessions);
    }

    /** @internal */
    public function add(WebSocketSession $session): void {
        $this->sessions[$session->getId()] = $session;
    }

    /** @internal */
    public function remove(int $id): ?WebSocketSession {
        $session = $this->sessions[$id] ?? null;
        unset($this->sessions[$id]);
        return $session;
    }
}
