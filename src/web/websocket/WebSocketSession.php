<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\http\HttpRequest;
use JsonException;
use JsonSerializable;

/**
 * One open WebSocket connection, handed to every {@see WebSocketHandler}
 * call. Sessions live in the worker that accepted the connection (see
 * {@see WebSocketSessions}); send() is safe from any coroutine of that
 * worker, e.g. while another message's task streams an answer.
 *
 * Implemented by the framework. In unit tests use
 * {@see \dev\winterframework\test\web\TestWebSocketSession}, which records
 * what is sent.
 */
interface WebSocketSession {

    /**
     * An opaque id, unique across workers and pods and never reused: safe
     * to store (e.g. to route a notification back to this connection).
     */
    public function getId(): string;

    /** The request path of the handshake, without the query string. */
    public function getPath(): string;

    /** The path the handler is registered for (as in #[WebSocketController]). */
    public function getHandlerPath(): string;

    /** The upgrade request: headers, cookies, query parameters, remote address. */
    public function getHandshake(): HttpRequest;

    public function getHeader(string $name): ?string;

    public function getCookie(string $name): ?string;

    /** Application data of this connection (e.g. the user id to find it by). */
    public function getAttribute(string $name, mixed $default = null): mixed;

    public function setAttribute(string $name, mixed $value): void;

    public function hasAttribute(string $name): bool;

    public function removeAttribute(string $name): void;

    /** @return array<string, mixed> */
    public function getAttributes(): array;

    /** True from the 101 response until the connection starts closing. */
    public function isOpen(): bool;

    /**
     * Sends a text frame; arrays and JsonSerializable objects are sent as
     * JSON. False when the connection is closed (never an exception for
     * that). Waits, in this coroutine only, while the client is too slow to
     * take more data.
     *
     * @throws JsonException for data that cannot be JSON-encoded
     */
    public function send(string|array|JsonSerializable $data): bool;

    /** Sends a binary frame; false when the connection is closed. */
    public function sendBinary(string $data): bool;

    /**
     * Starts closing the connection with a close frame. $code must be
     * sendable (1000-1003, 1007-1014, 3000-4999); the reason is cut to the
     * 123 bytes a close frame allows. onClose() follows once. False when
     * already closing or closed.
     *
     * @throws \InvalidArgumentException for a code that cannot be sent
     */
    public function close(int $code = WebSocketCloseCode::NORMAL, string $reason = ''): bool;

    /**
     * A cancellation token for a named task, e.g. the answer being
     * streamed. A running task of the same name is cancelled first, so a
     * new question replaces the previous answer. Closing the connection
     * cancels every task.
     */
    public function beginWork(string $name = 'default'): CancellationToken;

    /** Cancels the named task, if running. True when one was cancelled. */
    public function cancelWork(string $name = 'default', string $reason = 'cancelled'): bool;

    /** Forgets a finished task (only if $token is still the current one). */
    public function endWork(string $name, CancellationToken $token): void;

    public function getWork(string $name = 'default'): ?CancellationToken;

    /** When the connection opened (microtime). */
    public function getOpenedAt(): float;
}
