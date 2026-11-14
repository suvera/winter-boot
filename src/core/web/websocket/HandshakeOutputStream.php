<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

use dev\winterframework\io\stream\SwooleOutputStream;

/**
 * Output stream for responses to a WebSocket upgrade request (refusals such
 * as 401/403/404). Inside Swoole's handshake callback Response::write()
 * emits chunk framing without a Transfer-Encoding header, which clients
 * cannot parse, so the body is buffered and sent with end() in one piece.
 */
final class HandshakeOutputStream extends SwooleOutputStream {
    private string $buffer = '';

    public function write(string|int|float $data, ?int $length = null): int {
        $data = (string)$data;
        if ($length !== null) {
            $data = substr($data, 0, max(0, $length));
        }
        $this->buffer .= $data;
        return strlen($data);
    }

    public function writeChunk(string $data): bool {
        $this->buffer .= $data;
        return true;
    }

    public function close(): void {
        $body = $this->buffer;
        $this->buffer = '';
        $this->response->end($body);
    }
}
