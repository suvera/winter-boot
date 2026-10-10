<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

/**
 * Shared storage for distributed locks (a database table, Redis, ...).
 * A lock is a lease: a name held by one owner token until it is released
 * or, when a TTL is set, until the TTL passes. Every operation must be
 * atomic in the store, so two owners can never both hold a name.
 */
interface LockStore {

    /**
     * Takes the lease when nobody holds it or the holder's TTL has passed.
     *
     * @param int $ttlMs lease length in milliseconds; 0 = until released
     */
    public function acquire(string $name, string $owner, int $ttlMs): bool;

    /** Ends the lease, only if $owner still holds it. */
    public function release(string $name, string $owner): bool;

    /** Restarts the lease's TTL, only if $owner still holds it. */
    public function refresh(string $name, string $owner, int $ttlMs): bool;
}
