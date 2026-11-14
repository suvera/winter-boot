<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use dev\winterframework\core\web\websocket\WebSocketSessionRegistry;
use JsonSerializable;

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
 * deliver to its own sessions; a stored {@see WebSocketSession::getId()}
 * that get() does not know belongs to another worker or has closed.
 */
final class WebSocketSessions {

    /** @internal created by the framework */
    public function __construct(
        private WebSocketSessionRegistry $registry
    ) {
    }

    /** The open session with this id in this worker, or null. */
    public function get(string $id): ?WebSocketSession {
        $session = $this->registry->byId($id);
        return $session !== null && $session->isOpen() ? $session : null;
    }

    /**
     * Open sessions, optionally only those of one #[WebSocketController] path.
     *
     * @return WebSocketSession[]
     */
    public function all(?string $handlerPath = null): array {
        $out = [];
        foreach ($this->registry->all() as $session) {
            if ($session->isOpen() && ($handlerPath === null || $session->getHandlerPath() === $handlerPath)) {
                $out[] = $session;
            }
        }
        return $out;
    }

    /**
     * Open sessions whose attribute $name equals $value (strict comparison).
     *
     * @return WebSocketSession[]
     */
    public function findByAttribute(string $name, mixed $value): array {
        $found = [];
        foreach ($this->all() as $session) {
            if ($session->hasAttribute($name) && $session->getAttribute($name) === $value) {
                $found[] = $session;
            }
        }
        return $found;
    }

    /**
     * Sends $data to every open session whose attribute matches; returns
     * how many accepted it.
     */
    public function sendToAttribute(string $name, mixed $value, string|array|JsonSerializable $data): int {
        $sent = 0;
        foreach ($this->findByAttribute($name, $value) as $session) {
            if ($session->send($data)) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Number of open sessions in this worker. */
    public function count(): int {
        return count($this->all());
    }
}
