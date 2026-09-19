<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

use Closure;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Holds one lazily-built delegate per coroutine scope, plus a single
 * process-wide fallback used outside coroutines (and when the scoping
 * machinery itself fails — machinery failures degrade to logs, never 500s).
 *
 * Safety properties:
 * - Cleanup is registered with the scope's `defer()` exactly once per scope,
 *   so Swoole cid recycling can never resurrect a dead scope's delegate.
 * - A delegate that reports itself unusable (e.g. a closed EntityManager
 *   after a failed `wrapInTransaction()`) is discarded and rebuilt on next
 *   touch, so one failed unit of work never poisons its coroutine.
 * - `invalidateCurrent()` (wired to the façade's `close()`) destroys only
 *   the current scope's delegate; other coroutines are unaffected.
 * - Cap on concurrent DB connections (`maxDelegates`, shipped as 50,
 *   0 = unlimited but not recommended): when every DB connection is in use,
 *   a new scope waits up to `maxWaitMs` for one to be released and then
 *   throws `PoolExhaustedException`. The process-wide fallback outside
 *   coroutines is never capped.
 *
 * @template T of object
 */
final class CoroutineScopedPool {
    use Wlf4p;

    /** @var array<string, array{delegate: T, createdAt: float, deferRegistered: bool}> */
    private array $scoped = [];

    /** @var array{delegate: T, createdAt: float}|null */
    private ?array $fallback = null;

    private int $fallbacks = 0;

    /**
     * Wake-up signals for scopes waiting on a free DB connection slot.
     * Created lazily so non-Swoole runtimes never touch Swoole classes.
     */
    private ?Channel $releaseSignals = null;

    /**
     * @param Closure(): T $createDelegate build a virgin delegate (own connection)
     * @param Closure(T): void $destroyDelegate close + disconnect a delegate
     * @param Closure(T): bool|null $isUsable false forces rebuild on next touch
     * @param Closure(string, array): void|null $onEvent observability hook (create/dispose/fallback/...)
     * @param int $maxDelegates cap on concurrent scoped DB connections (0 = unlimited, not recommended)
     * @param int $maxWaitMs how long a new scope waits for a free DB connection before giving up
     */
    public function __construct(
        private Closure $createDelegate,
        private CoroutineScopeProvider $scopes,
        private Closure $destroyDelegate,
        private ?Closure $isUsable = null,
        private ?Closure $onEvent = null,
        private string $poolName = 'pool',
        private int $maxDelegates = 50,
        private int $maxWaitMs = 5000
    ) {
        $this->maxDelegates = max(0, $this->maxDelegates);
        $this->maxWaitMs = max(0, $this->maxWaitMs);
    }

    public function getMaxDelegates(): int {
        return $this->maxDelegates;
    }

    public function getMaxWaitMs(): int {
        return $this->maxWaitMs;
    }

    /**
     * @return T the delegate for the current scope (never throws for
     * missing scopes; only rethrows when even the fallback cannot be built)
     */
    public function current(): object {
        try {
            $scopeId = $this->scopes->getScopeId();
        } catch (Throwable $e) {
            return $this->useFallback('scope-resolution-failed', $e);
        }

        if ($scopeId === null) {
            return $this->fallbackDelegate();
        }

        try {
            $entry = $this->scoped[$scopeId] ?? null;
            if ($entry !== null && $this->usable($entry['delegate'])) {
                return $entry['delegate'];
            }
            if ($entry !== null) {
                $this->forget($scopeId, 'unusable');
            }
            if ($this->maxDelegates > 0) {
                $this->awaitSlot();
            }
            $delegate = ($this->createDelegate)();
            $this->scoped[$scopeId] = [
                'delegate' => $delegate,
                'createdAt' => microtime(true),
                'deferRegistered' => $entry['deferRegistered'] ?? false,
            ];
            if (!$this->scoped[$scopeId]['deferRegistered']) {
                $this->scoped[$scopeId]['deferRegistered'] = true;
                $provider = $this->scopes;
                $provider->defer(function () use ($scopeId): void {
                    $this->forget($scopeId, 'scope-end');
                });
            }
            $this->emit('create', ['scope' => $scopeId]);
            self::logDebug(
                __CLASS__ . ' opened a DB connection for scope ' . $scopeId
                . ' in ' . $this->poolName
            );
            return $delegate;
        } catch (PoolExhaustedException $e) {
            // Resource backpressure is operator policy, not a machinery
            // failure: it must stay loud, never degrade into a shared
            // fallback connection (that would reintroduce the interleaving
            // bug the pool exists to prevent).
            throw $e;
        } catch (Throwable $e) {
            return $this->useFallback('create-failed', $e);
        }
    }

    /**
     * Destroy the current scope's delegate so the next touch rebuilds it.
     * Used by façade `close()`: only the calling coroutine is affected.
     */
    public function invalidateCurrent(): void {
        try {
            $scopeId = $this->scopes->getScopeId();
        } catch (Throwable) {
            return;
        }
        if ($scopeId === null) {
            if ($this->fallback !== null) {
                $this->destroyQuietly($this->fallback['delegate']);
                $this->fallback = null;
                $this->emit('dispose', ['scope' => 'process']);
            }
            return;
        }
        $this->forget($scopeId, 'invalidated');
    }

    public function getActiveDelegateCount(): int {
        return count($this->scoped);
    }

    /** @return array<string, float> scope id => unix timestamp created */
    public function getDelegateCreatedAt(): array {
        $out = [];
        foreach ($this->scoped as $scopeId => $entry) {
            $out[$scopeId] = $entry['createdAt'];
        }
        return $out;
    }

    public function getFallbackCount(): int {
        return $this->fallbacks;
    }

    /** Destroy every delegate (worker shutdown, tenant eviction, tests). */
    public function closeAll(): void {
        foreach (array_keys($this->scoped) as $scopeId) {
            $this->forget($scopeId, 'close-all');
        }
        if ($this->fallback !== null) {
            $this->destroyQuietly($this->fallback['delegate']);
            $this->fallback = null;
        }
    }

    /** @return T */
    private function fallbackDelegate(): object {
        if ($this->fallback !== null && $this->usable($this->fallback['delegate'])) {
            return $this->fallback['delegate'];
        }
        if ($this->fallback !== null) {
            $this->destroyQuietly($this->fallback['delegate']);
            $this->fallback = null;
        }
        $delegate = ($this->createDelegate)();
        $this->fallback = ['delegate' => $delegate, 'createdAt' => microtime(true)];
        return $delegate;
    }

    /** @return T */
    private function useFallback(string $reason, Throwable $e): object {
        $this->fallbacks++;
        $this->emit('fallback', ['reason' => $reason]);
        self::logException($e, __CLASS__ . ' scope failure, using process fallback');
        return $this->fallbackDelegate();
    }

    private function forget(string $scopeId, string $reason): void {
        $entry = $this->scoped[$scopeId] ?? null;
        unset($this->scoped[$scopeId]);
        if ($entry !== null) {
            $this->destroyQuietly($entry['delegate']);
            $this->emit('dispose', ['scope' => $scopeId, 'reason' => $reason]);
            self::logDebug(
                __CLASS__ . ' closed the DB connection for scope ' . $scopeId
                . ' (' . $reason . ') in ' . $this->poolName
            );
        }
        $this->signalRelease();
    }

    /**
     * Block until a DB connection slot frees up or `maxWaitMs` passes.
     *
     * @throws PoolExhaustedException
     */
    private function awaitSlot(): void {
        $deadline = microtime(true) + $this->maxWaitMs / 1000;
        while (count($this->scoped) >= $this->maxDelegates) {
            $remainingMs = (int)(($deadline - microtime(true)) * 1000);
            if ($remainingMs <= 0 || !$this->waitForRelease($remainingMs)) {
                $this->emit('exhausted', ['active' => count($this->scoped)]);
                throw new PoolExhaustedException(
                    $this->poolName,
                    count($this->scoped),
                    $this->maxDelegates
                );
            }
        }
    }

    /**
     * Yield until a release signal arrives or the timeout passes. Returns
     * false when waiting is impossible (no Swoole, outside coroutines) so
     * the caller fails fast instead of hanging.
     *
     * Note: Swoole turns out-of-coroutine Channel use into a non-catchable
     * fatal, so no try/catch can protect the pop() below. The Coroutine::getCid()
     * reality check is load-bearing: it verifies with Swoole itself (not
     * just the scope provider) that waiting is safe.
     */
    private function waitForRelease(int $timeoutMs): bool {
        try {
            if (!extension_loaded('swoole') || !class_exists(Channel::class)) {
                return false;
            }
            if (!$this->scopes->isInCoroutine()) {
                return false;
            }
            $cid = Coroutine::getCid();
            if ($cid === false || $cid === -1) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        try {
            if ($this->releaseSignals === null) {
                $this->releaseSignals = new Channel(1);
            }
            return $this->releaseSignals->pop(max($timeoutMs, 1) / 1000) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function signalRelease(): void {
        if ($this->releaseSignals === null) {
            return;
        }
        try {
            if (!extension_loaded('swoole')) {
                return;
            }
            $cid = Coroutine::getCid();
            if ($cid === false || $cid === -1) {
                return;
            }
            if ($this->releaseSignals->isFull()) {
                return;
            }
            $this->releaseSignals->push(true);
        } catch (Throwable) {
        }
    }

    private function usable(object $delegate): bool {
        if ($this->isUsable === null) {
            return true;
        }
        try {
            return ($this->isUsable)($delegate);
        } catch (Throwable) {
            return false;
        }
    }

    private function destroyQuietly(object $delegate): void {
        try {
            ($this->destroyDelegate)($delegate);
        } catch (Throwable $e) {
            self::logException($e, __CLASS__ . ' delegate destroy failed');
        }
    }

    /** @param array<string, mixed> $ctx */
    private function emit(string $event, array $ctx): void {
        if ($this->onEvent === null) {
            return;
        }
        try {
            ($this->onEvent)($event, $ctx + ['pool' => $this->poolName]);
        } catch (Throwable) {
            // Observability must never break requests.
        }
    }
}
