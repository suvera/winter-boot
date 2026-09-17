<?php

declare(strict_types=1);

namespace dev\winterframework\web\session;

use dev\winterframework\pdbc\PdbcTemplate;
use InvalidArgumentException;
use Throwable;

/**
 * Database-backed session storage built on the framework's PDBC layer.
 *
 * Persists each session as one row with columns session_id as the
 * string primary key, username as NOT NULL text holding '' until the
 * login that names it (updates keep the stored name when the incoming
 * one is empty, so it is effectively written once), session_data holding base64 of the
 * serialized data — serialized payloads may contain NUL bytes, which
 * plain TEXT columns cannot reliably hold on every driver — expiry as
 * unix timestamp with 0 meaning never expires, created_at / updated_at
 * audit timestamps in unix time where created_at is set once on insert
 * and updated_at refreshes on every write, and session_type as an
 * application-defined smallint with 0 as default. Expected schema:
 *
 *   CREATE TABLE winter_sessions (
 *       session_id VARCHAR(128) NOT NULL PRIMARY KEY,
 *       username VARCHAR(255) NOT NULL,
 *       expiry BIGINT NOT NULL DEFAULT 0,
 *       created_at BIGINT NOT NULL DEFAULT 0,
 *       updated_at BIGINT NOT NULL DEFAULT 0,
 *       session_type SMALLINT NOT NULL DEFAULT 0,
 *       session_data TEXT
 *   );
 *
 * Upgrading a table created before these columns existed:
 *
 *   ALTER TABLE winter_sessions RENAME COLUMN id TO session_id;
 *   ALTER TABLE winter_sessions RENAME COLUMN payload TO session_data;
 *   ALTER TABLE winter_sessions ADD COLUMN username VARCHAR(255) NOT NULL DEFAULT '';
 *   ALTER TABLE winter_sessions ADD COLUMN session_type SMALLINT NOT NULL DEFAULT 0;
 *
 * Writes avoid database-specific upsert syntax on purpose (MySQL,
 * Postgres and Oracle all spell it differently): UPDATE first, INSERT
 * when nothing matched, one UPDATE retry when the INSERT loses a
 * concurrent-insert race. Works with any PdbcTemplate driver.
 */
class PdbcSessionStore implements \SessionHandlerInterface, SessionIdentityStore {
    public function __construct(
        private PdbcTemplate $db,
        private string $table = 'winter_sessions',
        private int $ttlSecs = 0
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table)) {
            throw new InvalidArgumentException(
                'Session table name must match [a-zA-Z0-9_]+, got "' . $this->table . '"'
            );
        }
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $row = $this->fetchRow($id);
        return $row === null ? '' : $row['data'];
    }

    public function readWithIdentity(string $id): array {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return ['data' => '', 'username' => '', 'sessionType' => 0];
        }
        return $row;
    }

    /**
     * @return array{data: string, username: string, sessionType: int}|null
     */
    private function fetchRow(string $id): ?array {
        try {
            $row = $this->db->queryForMap(
                'SELECT session_data, username, expiry, session_type FROM ' . $this->table
                    . ' WHERE session_id = ?',
                [$id]
            );
        } catch (Throwable) {
            // No row (drivers throw on empty single-row reads) or the
            // table is unreachable: callers treat null as "no session".
            return null;
        }

        $row = array_change_key_case($row, CASE_LOWER);
        $expiry = (int)($row['expiry'] ?? 0);
        if ($expiry > 0 && $expiry < time()) {
            return null;
        }
        $raw = $row['session_data'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = base64_decode($raw, true);
        if ($data === false) {
            return null;
        }
        return [
            'data' => $data,
            'username' => (string)($row['username'] ?? ''),
            'sessionType' => (int)($row['session_type'] ?? 0),
        ];
    }

    public function write(string $id, string $data): bool {
        return $this->writeWithIdentity($id, $data, '', 0);
    }

    public function writeWithIdentity(
        string $id,
        string $data,
        string $username,
        int $sessionType
    ): bool {
        $payload = base64_encode($data);
        $now = time();
        $expiry = $this->ttlSecs > 0 ? $now + $this->ttlSecs : 0;

        // username is set once at login: an empty incoming name leaves
        // the stored one untouched, so later saves can never wipe it.
        // A non-empty name always wins (explicit change still works).
        // The choice between the two statements happens here in PHP,
        // so both are plain portable SQL with no per-database tricks.
        // (Notably, a CASE on '' would misbehave on Oracle, where an
        // empty string is NULL and never equals anything.)
        if ($username !== '') {
            $updateSql = 'UPDATE ' . $this->table
                . ' SET session_data = ?, username = ?, expiry = ?, updated_at = ?,'
                . ' session_type = ? WHERE session_id = ?';
            $updateBinds = [$payload, $username, $expiry, $now, $sessionType, $id];
        } else {
            $updateSql = 'UPDATE ' . $this->table
                . ' SET session_data = ?, expiry = ?, updated_at = ?,'
                . ' session_type = ? WHERE session_id = ?';
            $updateBinds = [$payload, $expiry, $now, $sessionType, $id];
        }

        $affected = $this->db->update($updateSql, $updateBinds);
        if ($affected > 0) {
            return true;
        }
        try {
            $this->db->update(
                'INSERT INTO ' . $this->table
                    . ' (session_id, username, expiry, created_at, updated_at,'
                    . ' session_type, session_data) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $username, $expiry, $now, $now, $sessionType, $payload]
            );
            return true;
        } catch (Throwable) {
            // Lost an insert race: the row exists now, so update it.
            $this->db->update($updateSql, $updateBinds);
            return true;
        }
    }

    public function destroy(string $id): bool {
        $this->db->update(
            'DELETE FROM ' . $this->table . ' WHERE session_id = ?',
            [$id]
        );
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return $this->db->update(
            'DELETE FROM ' . $this->table . ' WHERE expiry > 0 AND expiry < ?',
            [time()]
        );
    }
}
