<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use dev\winterframework\web\http\HttpRequest;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * One open WebSocket connection, handed to every {@see WebSocketHandler}
 * call. Sessions live in the worker that accepted the connection (see
 * {@see WebSocketSessions}); send() is safe from any coroutine of that
 * worker, e.g. while another message's task streams an answer.
 */
class WebSocketSession {
    private const OPENING = 0;
    private const OPEN = 1;
    private const CLOSING = 2;
    private const CLOSED = 3;

    private int $state = self::OPENING;
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, CancellationToken> */
    private array $work = [];
    private ?Channel $openGate = null;
    private bool $openCompleted = false;
    private ?int $closeCode = null;
    private string $closeReason = '';
    private float $lastSeen;
    private float $openedAt;
    private int $inFlight = 0;
    private int $messagesReceived = 0;

    public function __construct(
        private int $id,
        private string $path,
        private HttpRequest $handshake,
        private WebSocketTransport $transport,
        private string $handlerPath = ''
    ) {
        $this->openedAt = $this->lastSeen = microtime(true);
        if (class_exists(Channel::class) && Coroutine::getCid() > 0) {
            $this->openGate = new Channel(1);
        }
    }

    /** The connection id (Swoole fd), unique within the server while open. */
    public function getId(): int {
        return $this->id;
    }

    /** The request path of the handshake, without the query string. */
    public function getPath(): string {
        return $this->path;
    }

    /** The path the handler is registered for (as in #[WebSocketController]). */
    public function getHandlerPath(): string {
        return $this->handlerPath;
    }

    /** The upgrade request: headers, cookies, query parameters, remote address. */
    public function getHandshake(): HttpRequest {
        return $this->handshake;
    }

    public function getHeader(string $name): ?string {
        return $this->handshake->getFirstHeader($name);
    }

    public function getCookie(string $name): ?string {
        return $this->handshake->getCookie($name);
    }

    public function getAttribute(string $name, mixed $default = null): mixed {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function setAttribute(string $name, mixed $value): void {
        $this->attributes[$name] = $value;
    }

    public function hasAttribute(string $name): bool {
        return array_key_exists($name, $this->attributes);
    }

    public function removeAttribute(string $name): void {
        unset($this->attributes[$name]);
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array {
        return $this->attributes;
    }

    /** True from the 101 response until the connection starts closing. */
    public function isOpen(): bool {
        return ($this->state === self::OPENING || $this->state === self::OPEN)
            && $this->transport->isEstablished($this->id);
    }

    /**
     * Sends a text frame; arrays and JsonSerializable objects are sent as
     * JSON. False (never an exception) when the connection is closed or
     * the data cannot be encoded.
     */
    public function send(string|array|JsonSerializable $data): bool {
        if (!is_string($data)) {
            try {
                $data = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                return false;
            }
        }
        return $this->push($data, false);
    }

    /** Sends a binary frame; false when the connection is closed. */
    public function sendBinary(string $data): bool {
        return $this->push($data, true);
    }

    private function push(string $data, bool $binary): bool {
        if ($this->state !== self::OPENING && $this->state !== self::OPEN) {
            return false;
        }
        try {
            return $this->transport->push($this->id, $data, $binary);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Starts closing the connection with a close frame. $code must be
     * sendable (1000-1003, 1007-1014, 3000-4999); the reason is cut to the
     * 123 bytes a close frame allows. onClose() follows once. False when
     * already closing or closed.
     */
    public function close(int $code = WebSocketCloseCode::NORMAL, string $reason = ''): bool {
        if (!WebSocketCloseCode::isSendable($code)) {
            throw new InvalidArgumentException('Invalid WebSocket close code ' . $code);
        }
        if ($this->state !== self::OPENING && $this->state !== self::OPEN) {
            return false;
        }
        $this->state = self::CLOSING;
        $reason = self::truncateReason($reason);
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->cancelAllWork('connection closing');
        try {
            return $this->transport->disconnect($this->id, $code, $reason);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A cancellation token for a named task, e.g. the answer being
     * streamed. A running task of the same name is cancelled first, so a
     * new question replaces the previous answer. Closing the connection
     * cancels every task.
     */
    public function beginWork(string $name = 'default'): CancellationToken {
        ($this->work[$name] ?? null)?->cancel('replaced');
        $token = new CancellationToken();
        if ($this->state === self::CLOSING || $this->state === self::CLOSED) {
            $token->cancel('connection closed');
        }
        $this->work[$name] = $token;
        return $token;
    }

    /** Cancels the named task, if running. True when one was cancelled. */
    public function cancelWork(string $name = 'default', string $reason = 'cancelled'): bool {
        $token = $this->work[$name] ?? null;
        if ($token === null || $token->isCancelled()) {
            return false;
        }
        $token->cancel($reason);
        return true;
    }

    /** Forgets a finished task (only if $token is still the current one). */
    public function endWork(string $name, CancellationToken $token): void {
        if (($this->work[$name] ?? null) === $token) {
            unset($this->work[$name]);
        }
    }

    public function getWork(string $name = 'default'): ?CancellationToken {
        return $this->work[$name] ?? null;
    }

    public function getOpenedAt(): float {
        return $this->openedAt;
    }

    public function getLastSeen(): float {
        return $this->lastSeen;
    }

    public function getMessagesReceived(): int {
        return $this->messagesReceived;
    }

    // ---- Framework side (dev\winterframework\core\web\websocket) ----------

    /** @internal Any frame from the peer: resets the idle timer. */
    public function touch(): void {
        $this->lastSeen = microtime(true);
    }

    /** @internal */
    public function countMessage(): void {
        $this->messagesReceived++;
    }

    /** @internal Messages being handled right now. */
    public function enterMessage(): int {
        return ++$this->inFlight;
    }

    /** @internal */
    public function leaveMessage(): void {
        $this->inFlight = max(0, $this->inFlight - 1);
    }

    /** @internal onOpen() returned; releases waiting messages. */
    public function markOpenCompleted(): void {
        $this->openCompleted = true;
        if ($this->state === self::OPENING) {
            $this->state = self::OPEN;
        }
        if ($this->openGate !== null) {
            $this->openGate->close();
            $this->openGate = null;
        }
    }

    /** @internal Waits (up to $timeout seconds) until onOpen() has returned. */
    public function awaitOpen(float $timeout): bool {
        // A channel can only be waited on inside a coroutine.
        if (!$this->openCompleted && $this->openGate !== null && Coroutine::getCid() > 0) {
            $this->openGate->pop($timeout);
        }
        return $this->openCompleted;
    }

    /** @internal The peer sent a close frame. */
    public function recordPeerClose(int $code, string $reason): void {
        if ($this->closeCode === null) {
            $this->closeCode = $code;
            $this->closeReason = self::truncateReason($reason);
        }
        if ($this->state === self::OPENING || $this->state === self::OPEN) {
            $this->state = self::CLOSING;
        }
        $this->cancelAllWork('connection closing');
    }

    /**
     * @internal Marks the session closed. True only the first time, so
     * onClose() runs exactly once whichever path gets here first.
     *
     * @return array{0: int, 1: string}|null close code and reason, or null when already closed
     */
    public function markClosed(): ?array {
        if ($this->state === self::CLOSED) {
            return null;
        }
        $this->state = self::CLOSED;
        $this->cancelAllWork('connection closed');
        return [$this->closeCode ?? WebSocketCloseCode::ABNORMAL, $this->closeReason];
    }

    /** @internal */
    public function isOpenCompleted(): bool {
        return $this->openCompleted;
    }

    private function cancelAllWork(string $reason): void {
        foreach ($this->work as $token) {
            $token->cancel($reason);
        }
    }

    private static function truncateReason(string $reason): string {
        if (strlen($reason) <= 123) {
            return $reason;
        }
        // Never cut a multi-byte character in half.
        return mb_strcut($reason, 0, 123, 'UTF-8');
    }
}
