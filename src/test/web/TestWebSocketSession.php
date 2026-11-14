<?php

declare(strict_types=1);

namespace dev\winterframework\test\web;

use dev\winterframework\core\web\websocket\DefaultWebSocketSession;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\InternalHttpRequest;

/**
 * An open {@see \dev\winterframework\web\websocket\WebSocketSession} for
 * unit tests of WebSocket handlers: it behaves like a real connection (close
 * rules, attributes, work tokens) but records what would be sent.
 *
 * ```php
 * $session = new TestWebSocketSession('/ws/assistant');
 * $session->setAttribute('userId', 42);
 * $handler->onMessage($session, WebSocketMessage::text('{"type":"echo","text":"hi"}'));
 * $session->getSentJson();       // [['type' => 'echo', 'text' => 'hi']]
 * $session->disconnect();        // the client leaves: send() returns false
 * ```
 */
class TestWebSocketSession extends DefaultWebSocketSession {
    private TestWebSocketTransport $recorder;

    /**
     * @param HttpRequest|null $handshake the upgrade request (headers, cookies); an empty GET by default
     */
    public function __construct(string $path = '/ws/test', ?HttpRequest $handshake = null) {
        $recorder = new TestWebSocketTransport();
        parent::__construct(
            DefaultWebSocketSession::newId(),
            1,
            $path,
            $handshake ?? new InternalHttpRequest(new HttpRequest(), 'GET', $path),
            $recorder,
            $path
        );
        $this->recorder = $recorder;
        $this->markOpenCompleted();
    }

    /**
     * Every frame sent, in order.
     *
     * @return list<array{data: string, binary: bool}>
     */
    public function getSentFrames(): array {
        return $this->recorder->frames;
    }

    /** @return list<string> the text frames */
    public function getSentMessages(): array {
        return array_values(array_map(
            fn(array $f) => $f['data'],
            array_filter($this->recorder->frames, fn(array $f) => !$f['binary'])
        ));
    }

    /** @return list<mixed> the text frames decoded as JSON */
    public function getSentJson(): array {
        return array_map(
            fn(string $m) => json_decode($m, true, 512, JSON_THROW_ON_ERROR),
            $this->getSentMessages()
        );
    }

    /** The close code sent with close(), or null while open. */
    public function getCloseCode(): ?int {
        return $this->recorder->closeCode;
    }

    public function getCloseReason(): ?string {
        return $this->recorder->closeReason;
    }

    /** The client leaves without a close frame: later sends return false. */
    public function disconnect(): void {
        $this->recorder->established = false;
    }
}
