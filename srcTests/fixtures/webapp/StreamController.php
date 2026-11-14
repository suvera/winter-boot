<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\ResponseWriter;
use dev\winterframework\web\http\SseEmitter;
use dev\winterframework\web\http\StreamingResponseEntity;
use dev\winterframework\web\MediaType;
use RuntimeException;
use Swoole\Coroutine;

/**
 * Streamed responses for srcTests/swoole/StreamingResponseSwooleTest. All
 * /stream routes pass FixtureAuthInterceptor (cookie user=...).
 */
#[RestController]
class StreamController {

    /** @var array<string, array{writes: int, reason: string}> Worker-local (one worker). */
    public static array $stopped = [];

    #[GetMapping(path: '/stream/chunks')]
    public function chunks(#[RequestParam] int $n = 5, #[RequestParam] float $delay = 0.1): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out) use ($n, $delay): void {
            for ($i = 0; $i < $n; $i++) {
                $out->write('chunk-' . $i . '@' . sprintf('%.3f', microtime(true)) . "\n");
                Coroutine::sleep($delay);
            }
        }, MediaType::TEXT_PLAIN);
    }

    /** Writes the coroutine context set by the interceptor, from inside the stream. */
    #[GetMapping(path: '/stream/user')]
    public function user(): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out): void {
            Coroutine::sleep(0.05);
            $out->write('user=' . FixtureContext::user());
        }, MediaType::TEXT_PLAIN);
    }

    #[GetMapping(path: '/stream/sse')]
    public function sse(): StreamingResponseEntity {
        return StreamingResponseEntity::sse(function (SseEmitter $sse): void {
            $sse->event('token', ['text' => "Hel"], '1');
            $sse->event('token', ['text' => "lo\nworld"], '2');
            $sse->data("multi\nline");
            Coroutine::sleep(0.5);
            $sse->event('done', '[DONE]');
        }, 0.15);
    }

    /** Writes until the client is gone, then records why it stopped. */
    #[GetMapping(path: '/stream/forever')]
    public function forever(#[RequestParam] string $id): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out) use ($id): void {
            $writes = 0;
            while ($out->write("tick $writes\n")) {
                $writes++;
                Coroutine::sleep(0.05);
                if ($writes > 400) {
                    self::$stopped[$id] = ['writes' => $writes, 'reason' => 'limit'];
                    return;
                }
            }
            self::$stopped[$id] = ['writes' => $writes, 'reason' => 'write-false'];
        }, MediaType::TEXT_PLAIN);
    }

    /** Writes once, then waits without writing, polling isConnected(). */
    #[GetMapping(path: '/stream/idle')]
    public function idle(#[RequestParam] string $id): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out) use ($id): void {
            $out->write("started\n");
            $start = microtime(true);
            while ($out->isConnected()) {
                if (microtime(true) - $start > 10) {
                    self::$stopped[$id] = ['writes' => 1, 'reason' => 'limit'];
                    return;
                }
                Coroutine::sleep(0.05);
            }
            self::$stopped[$id] = ['writes' => 1, 'reason' => 'disconnected'];
        }, MediaType::TEXT_PLAIN);
    }

    #[GetMapping(path: '/stream/fail-early')]
    public function failEarly(): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out): void {
            throw new RuntimeException('producer failed before writing');
        });
    }

    #[GetMapping(path: '/stream/fail-late')]
    public function failLate(): StreamingResponseEntity {
        return StreamingResponseEntity::of(function (ResponseWriter $out): void {
            $out->write("partial\n");
            throw new RuntimeException('producer failed mid-stream');
        }, MediaType::TEXT_PLAIN);
    }

    #[GetMapping(path: '/probe/stream-status')]
    public function status(#[RequestParam] string $id): array {
        return self::$stopped[$id] ?? ['writes' => -1, 'reason' => 'running'];
    }
}
