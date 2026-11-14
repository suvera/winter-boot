<?php

declare(strict_types=1);

namespace dev\winterframework\test\web;

use dev\winterframework\core\web\websocket\WebSocketTransport;

/** @internal recording transport behind {@see TestWebSocketSession} */
final class TestWebSocketTransport implements WebSocketTransport {
    /** @var list<array{data: string, binary: bool}> */
    public array $frames = [];
    public ?int $closeCode = null;
    public ?string $closeReason = null;
    public bool $established = true;

    public function push(int $fd, string $data, bool $binary): bool {
        if (!$this->established) {
            return false;
        }
        $this->frames[] = ['data' => $data, 'binary' => $binary];
        return true;
    }

    public function disconnect(int $fd, int $code, string $reason): bool {
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->established = false;
        return true;
    }

    public function ping(int $fd): bool {
        return $this->established;
    }

    public function pong(int $fd, string $data): bool {
        return $this->established;
    }

    public function isEstablished(int $fd): bool {
        return $this->established;
    }
}
