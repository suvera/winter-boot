<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use dev\winterframework\web\http\HttpRequest;

/**
 * Handles the connections of one {@see \dev\winterframework\stereotype\web\WebSocketController}
 * path. Implement {@see WebSocketErrorHandler} too to see failures.
 *
 * Every call runs in its own coroutine with the connection's context
 * restored (the handshake request is the current request, and each
 * {@see WebSocketContextHook} has run restore()). Messages of one
 * connection can therefore run concurrently: a long onMessage() (an AI
 * answer) does not hold back the next message (a "stop"). See
 * {@see WebSocketSession::beginWork()} for cancelling running work.
 */
interface WebSocketHandler {

    /**
     * The connection is open (the 101 response went out after the
     * interceptors accepted the handshake). Reject it with
     * `$session->close(WebSocketCloseCode::POLICY_VIOLATION, 'reason')`.
     * Messages that arrive meanwhile wait until this returns.
     */
    public function onOpen(WebSocketSession $session, HttpRequest $handshake): void;

    public function onMessage(WebSocketSession $session, WebSocketMessage $message): void;

    /**
     * Called exactly once per opened connection, after onOpen() returned:
     * the peer's close code, the code passed to close(), or 1006 when the
     * connection dropped without a close frame.
     */
    public function onClose(WebSocketSession $session, int $code, string $reason): void;
}
