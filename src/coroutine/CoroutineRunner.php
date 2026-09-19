<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use function Swoole\Coroutine\run;

/**
 * Runs a unit of work on a virgin coroutine scope.
 *
 * Long-lived coroutines (daemons, schedulers, websocket handlers) accumulate
 * identity-map state exactly like the old process-wide singleton did. Wrap
 * each loop iteration with this helper so it runs on a fresh delegate whose
 * cleanup is registered via `defer()`.
 *
 * Outside coroutines (or without Swoole) the callback simply runs inline.
 */
final class CoroutineRunner {

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function runInFreshCoroutine(
        callable $fn,
        ?CoroutineScopeProvider $scopes = null
    ): mixed {
        $scopes ??= CoroutineScopeProviders::shared();

        if (!SwooleCoroutineScopeProvider::isAvailable()) {
            return $fn();
        }

        if ($scopes->isInCoroutine()) {
            $channel = new Channel(1);
            Coroutine::create(static function () use ($fn, $channel): void {
                try {
                    $channel->push(['ok', $fn()]);
                } catch (Throwable $e) {
                    $channel->push(['err', $e]);
                }
            });
            /** @var array{0:string,1:mixed} $result */
            $result = $channel->pop();
            if ($result[0] === 'err') {
                throw $result[1];
            }
            return $result[1];
        }

        // Plain CLI with Swoole loaded: run on the event loop so a fresh
        // coroutine scope (and its defer cleanup) still applies.
        $result = null;
        $error = null;
        $ran = false;
        try {
            run(static function () use ($fn, &$result, &$error, &$ran): void {
                try {
                    $result = $fn();
                } catch (Throwable $e) {
                    $error = $e;
                }
                $ran = true;
            });
        } catch (Throwable $e) {
            // Already inside a runtime (e.g. a server worker): just run inline.
            return $fn();
        }

        if (!$ran) {
            return $fn();
        }
        if ($error !== null) {
            throw $error;
        }
        return $result;
    }
}
