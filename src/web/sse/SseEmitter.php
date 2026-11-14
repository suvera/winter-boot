<?php

declare(strict_types=1);

namespace dev\winterframework\web\sse;

use dev\winterframework\util\concurrent\CancellationToken;
use dev\winterframework\web\http\ResponseWriter;
use dev\winterframework\web\http\StreamingResponseEntity;

use InvalidArgumentException;
use JsonException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Server-sent events (text/event-stream) over a streamed response. Get one
 * from {@see StreamingResponseEntity::sse()}:
 *
 * ```php
 * return StreamingResponseEntity::sse(function (SseEmitter $sse) {
 *     $sse->heartbeat(15);
 *     foreach ($tokens as $token) {
 *         if (!$sse->event('token', ['text' => $token])) {
 *             return; // client gone
 *         }
 *     }
 *     $sse->event('done', '[DONE]');
 * });
 * ```
 *
 * Every method writes one complete event as one chunk and returns false
 * once the client is gone. Arrays and objects are sent as JSON; strings as
 * they are, one `data:` line per line.
 */
final class SseEmitter {
    private ?Channel $heartbeatStop = null;

    public function __construct(
        private ResponseWriter $writer
    ) {
    }

    /**
     * One event: `event: <name>` (omitted when null), `id:` when given, and
     * the data. Names and ids must not contain line breaks.
     */
    public function event(?string $name, mixed $data, ?string $id = null): bool {
        $out = '';
        if ($name !== null) {
            $out .= 'event: ' . self::field($name, 'event name') . "\n";
        }
        if ($id !== null) {
            $out .= 'id: ' . self::field($id, 'event id') . "\n";
        }
        return $this->writer->write($out . self::dataLines($data) . "\n");
    }

    /**
     * Sends a parsed or prepared {@see SseEvent} as is (for example one
     * received from an upstream service with SseParser): its event name
     * (omitted when "message"), id, retry and data.
     */
    public function send(SseEvent $event): bool {
        $out = '';
        if ($event->event !== '' && $event->event !== 'message') {
            $out .= 'event: ' . self::field($event->event, 'event name') . "\n";
        }
        if ($event->id !== null) {
            $out .= 'id: ' . self::field($event->id, 'event id') . "\n";
        }
        if ($event->retry !== null) {
            $out .= 'retry: ' . max(0, $event->retry) . "\n";
        }
        return $this->writer->write($out . self::dataLines($event->data) . "\n");
    }

    /** An unnamed event (a browser EventSource delivers it to onmessage). */
    public function data(mixed $data, ?string $id = null): bool {
        return $this->event(null, $data, $id);
    }

    /** A comment line: ignored by clients, keeps proxies from timing out. */
    public function comment(string $text = ''): bool {
        $out = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $line) {
            $out .= ':' . ($line !== '' ? ' ' . $line : '') . "\n";
        }
        return $this->writer->write($out . "\n");
    }

    /** Tells the client how long to wait before reconnecting. */
    public function retry(int $milliseconds): bool {
        return $this->writer->write('retry: ' . max(0, $milliseconds) . "\n\n");
    }

    /**
     * Sends a `: ping` comment every $seconds until the stream ends, from a
     * separate coroutine, so idle proxies keep the connection open and a
     * gone client is noticed while the producer waits. Needs Swoole; a
     * no-op elsewhere. Calling it again changes the interval.
     */
    public function heartbeat(float $seconds): void {
        $this->stopHeartbeat();
        if ($seconds <= 0 || !class_exists(Coroutine::class) || Coroutine::getCid() <= 0) {
            return;
        }
        $stop = new Channel(1);
        $this->heartbeatStop = $stop;
        $writer = $this->writer;
        Coroutine::create(static function () use ($stop, $writer, $seconds): void {
            // pop() returns false on timeout (time to ping) and when the
            // channel is closed (stop); errCode tells them apart.
            while ($stop->pop($seconds) === false && $stop->errCode === SWOOLE_CHANNEL_TIMEOUT) {
                if (!$writer->write(": ping\n\n")) {
                    return;
                }
            }
        });
    }

    public function stopHeartbeat(): void {
        if ($this->heartbeatStop !== null) {
            $this->heartbeatStop->close();
            $this->heartbeatStop = null;
        }
    }

    public function isConnected(): bool {
        return $this->writer->isConnected();
    }

    /** Cancelled once the client disconnects: see {@see ResponseWriter::getCancellationToken()}. */
    public function getCancellationToken(): CancellationToken {
        return $this->writer->getCancellationToken();
    }

    public function getWriter(): ResponseWriter {
        return $this->writer;
    }

    private static function field(string $value, string $what): string {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException("SSE $what must not contain line breaks");
        }
        return $value;
    }

    /** @throws JsonException */
    public static function dataLines(mixed $data): string {
        if (!is_string($data)) {
            $data = is_scalar($data) || $data === null
                ? json_encode($data, JSON_THROW_ON_ERROR)
                : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $out = '';
        foreach (preg_split('/\r\n|\r|\n/', $data) ?: [''] as $line) {
            $out .= 'data: ' . $line . "\n";
        }
        return $out;
    }
}
