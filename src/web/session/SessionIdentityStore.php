<?php

declare(strict_types=1);

namespace dev\winterframework\web\session;

/**
 * Optional capability for session stores that persist per-session
 * identity columns (username, session type) alongside the payload.
 *
 * SessionManager uses these methods when the configured store offers
 * them and falls back to plain read()/write() otherwise, so identity
 * support never breaks file, Redis or custom stores. sessionType values
 * are application-defined (0 = default).
 */
interface SessionIdentityStore {
    /**
     * @return array{data: string, username: string, sessionType: int}
     *   data is '' when the id has no live row.
     */
    public function readWithIdentity(string $id): array;

    /**
     * An empty $username keeps the stored name; a non-empty one wins.
     */
    public function writeWithIdentity(
        string $id,
        string $data,
        string $username,
        int $sessionType
    ): bool;
}
