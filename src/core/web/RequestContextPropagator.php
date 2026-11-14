<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\web\http\HttpRequest;

/**
 * Carries an application's request-scoped context (tenant, user,
 * principal) from the request that established it into code that later runs
 * in another coroutine, where coroutine-local state is gone.
 *
 * Used for WebSocket connections: the handshake passes the interceptors,
 * which set up the context; every message and the close then run in fresh
 * coroutines, with the snapshot restored around each call.
 *
 * ```php
 * class TenantContextPropagator implements RequestContextPropagator {
 *     public function capture(HttpRequest $request): mixed {
 *         return TenantContext::current();             // after the interceptors ran
 *     }
 *     public function restore(mixed $snapshot): void {
 *         TenantContext::set($snapshot);
 *     }
 *     public function clear(): void {
 *         TenantContext::clear();
 *     }
 * }
 *
 * // in a WebMvcConfigurer
 * $registry->addContextPropagator(new TenantContextPropagator());
 * ```
 *
 * The snapshot is opaque to the framework and kept with the connection; it
 * is not visible as a session attribute. A restore() that throws fails
 * closed: the call it guards does not run. clear() always runs after.
 */
interface RequestContextPropagator {

    /**
     * Takes a snapshot of the current context, in the request's coroutine
     * after the interceptors accepted it. Return whatever restore() needs.
     */
    public function capture(HttpRequest $request): mixed;

    /** Re-establishes the context in the current coroutine. */
    public function restore(mixed $snapshot): void;

    /** Removes it again from the current coroutine. */
    public function clear(): void;
}
