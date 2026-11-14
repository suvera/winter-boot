<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use Throwable;

/**
 * Optional for a {@see WebSocketHandler}: receives exceptions thrown by its
 * onOpen() / onMessage() / onClose(). Without it they are logged. The
 * connection stays open unless the handler closes it (an exception from
 * onOpen() closes it with 1011).
 */
interface WebSocketErrorHandler {

    public function onError(WebSocketSession $session, Throwable $e): void;
}
