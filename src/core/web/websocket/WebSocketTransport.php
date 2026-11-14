<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

/**
 * Frame-level access to the server's connections, used by
 * {@see DefaultWebSocketSession}. The Swoole implementation wraps
 * Swoole\WebSocket\Server.
 *
 * @internal
 */
interface WebSocketTransport {

    /** Sends one frame; false when the connection is gone or not a WebSocket. */
    public function push(int $fd, string $data, bool $binary): bool;

    /** Sends a close frame with $code/$reason and closes the connection. */
    public function disconnect(int $fd, int $code, string $reason): bool;

    public function ping(int $fd): bool;

    public function pong(int $fd, string $data): bool;

    /** Whether the connection is an established WebSocket. */
    public function isEstablished(int $fd): bool;
}
