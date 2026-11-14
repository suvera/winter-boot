<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

use dev\winterframework\web\websocket\WebSocketTransport;
use Swoole\WebSocket\Server;

/**
 * {@see WebSocketTransport} over Swoole\WebSocket\Server. Swoole warns when a
 * connection is already gone; those warnings are silenced because the
 * false return reports it.
 */
final class SwooleWebSocketTransport implements WebSocketTransport {

    public function __construct(
        private Server $server
    ) {
    }

    public function push(int $fd, string $data, bool $binary): bool {
        if (!$this->server->isEstablished($fd)) {
            return false;
        }
        return @$this->server->push($fd, $data, $binary ? WEBSOCKET_OPCODE_BINARY : WEBSOCKET_OPCODE_TEXT) === true;
    }

    public function disconnect(int $fd, int $code, string $reason): bool {
        if (!$this->server->isEstablished($fd)) {
            return @$this->server->close($fd) === true;
        }
        return @$this->server->disconnect($fd, $code, $reason) === true;
    }

    public function ping(int $fd): bool {
        return $this->server->isEstablished($fd)
            && @$this->server->push($fd, '', WEBSOCKET_OPCODE_PING) === true;
    }

    public function pong(int $fd, string $data): bool {
        return $this->server->isEstablished($fd)
            && @$this->server->push($fd, $data, WEBSOCKET_OPCODE_PONG) === true;
    }

    public function isEstablished(int $fd): bool {
        return $this->server->isEstablished($fd);
    }
}
