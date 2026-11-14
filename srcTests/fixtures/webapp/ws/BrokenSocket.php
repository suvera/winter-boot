<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp\ws;

use dev\winterframework\stereotype\web\WebSocketController;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\websocket\WebSocketHandler;
use dev\winterframework\web\websocket\WebSocketMessage;
use dev\winterframework\web\websocket\WebSocketSession;
use RuntimeException;

/** A handler bean that cannot be built: its handshake must fail with a plain 500. */
#[WebSocketController(path: '/ws/broken')]
class BrokenSocket implements WebSocketHandler {

    public function __construct() {
        throw new RuntimeException('cannot build this handler');
    }

    public function onOpen(WebSocketSession $session, HttpRequest $handshake): void {
    }

    public function onMessage(WebSocketSession $session, WebSocketMessage $message): void {
    }

    public function onClose(WebSocketSession $session, int $code, string $reason): void {
    }
}
