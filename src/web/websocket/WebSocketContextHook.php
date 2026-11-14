<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use dev\winterframework\web\http\HttpRequest;

/**
 * Carries an application's request-scoped context (tenant, user,
 * principal) from the WebSocket handshake to every later call of the
 * connection. Interceptors establish that context for the handshake
 * request only; each message runs in a fresh coroutine where it is gone.
 *
 * Register hooks in a WebMvcConfigurer:
 * `$registry->addWebSocketContextHook(new TenantSocketContext())`.
 *
 * ```php
 * class TenantSocketContext implements WebSocketContextHook {
 *     public function capture(WebSocketSession $s, HttpRequest $handshake): void {
 *         $s->setAttribute('userId', TenantContext::current()->userId);
 *     }
 *     public function restore(WebSocketSession $s): void {
 *         TenantContext::set(userId: $s->getAttribute('userId'));
 *     }
 *     public function clear(WebSocketSession $s): void {
 *         TenantContext::clear();
 *     }
 * }
 * ```
 *
 * A restore() that throws fails closed: the handler is not called and the
 * connection closes with 1011. clear() always runs after a call, also when
 * it failed.
 */
interface WebSocketContextHook {

    /**
     * Once, in the handshake coroutine, after the interceptors accepted the
     * request and before onOpen(): copy what they established onto the
     * session (attributes).
     */
    public function capture(WebSocketSession $session, HttpRequest $handshake): void;

    /** Before every onMessage() / onClose(), in that call's coroutine. */
    public function restore(WebSocketSession $session): void;

    /** After every onMessage() / onClose(), in that call's coroutine. */
    public function clear(WebSocketSession $session): void;
}
