<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

/**
 * Identifies the current execution scope (Swoole coroutine or fallback).
 *
 * A stable, unique id is returned for every live coroutine; `null` is
 * returned outside any coroutine (short-lived CLI, plain PHP). All
 * coroutine-scoped pools in this library key their delegates off this id.
 */
interface CoroutineScopeProvider {

    /**
     * True when the caller runs inside a Swoole coroutine.
     */
    public function isInCoroutine(): bool;

    /**
     * Stable id for the current coroutine, or null outside coroutines.
     */
    public function getScopeId(): ?string;

    /**
     * Run $fn when the current scope ends. Outside coroutines this is a
     * no-op (the process owns the fallback delegate).
     *
     * @param callable(): void $fn
     */
    public function defer(callable $fn): void;
}
