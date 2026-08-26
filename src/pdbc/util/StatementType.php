<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\util;

/**
 * Utility to classify SQL statements by their primary operation type.
 *
 * Handles leading whitespace, single-line comments (--), block comments (/* ... * /),
 * and different casing. Also detects CTE-prefixed INSERT statements (WITH ... INSERT ...).
 *
 * Limitations:
 * - Does not parse nested block comments.
 * - CTE detection looks for INSERT after the first top-level SELECT/INSERT/UPDATE/DELETE/MERGE
 *   following a WITH clause. Complex CTEs with multiple nested WITHs may not be detected.
 * - Does not distinguish INSERT ... SELECT from plain INSERT for generated-key purposes.
 */
final class StatementType {

    public const INSERT = 'INSERT';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const SELECT = 'SELECT';
    public const OTHER  = 'OTHER';

    /**
     * Classify the given SQL string.
     *
     * @param string $sql Raw SQL, possibly with leading whitespace and comments.
     * @return string One of the StatementType constants.
     */
    public static function classify(string $sql): string {
        $sql = trim($sql);
        if ($sql === '') {
            return self::OTHER;
        }

        $upper = strtoupper($sql);

        // Fast path: no comments, no CTE
        if (self::startsWithKeyword($upper, 'INSERT')) {
            return self::INSERT;
        }
        if (self::startsWithKeyword($upper, 'UPDATE')) {
            return self::UPDATE;
        }
        if (self::startsWithKeyword($upper, 'DELETE')) {
            return self::DELETE;
        }
        if (self::startsWithKeyword($upper, 'SELECT')) {
            return self::SELECT;
        }

        // Slow path: strip comments and re-check
        $stripped = self::stripComments($sql);
        $strippedUpper = strtoupper(trim($stripped));

        if (self::startsWithKeyword($strippedUpper, 'INSERT')) {
            return self::INSERT;
        }
        if (self::startsWithKeyword($strippedUpper, 'UPDATE')) {
            return self::UPDATE;
        }
        if (self::startsWithKeyword($strippedUpper, 'DELETE')) {
            return self::DELETE;
        }
        if (self::startsWithKeyword($strippedUpper, 'SELECT')) {
            return self::SELECT;
        }

        // CTE: WITH ... INSERT ...
        if (self::startsWithKeyword($strippedUpper, 'WITH')) {
            $afterWith = self::findFirstDmlAfterCTE($strippedUpper);
            if ($afterWith !== null) {
                return $afterWith;
            }
        }

        return self::OTHER;
    }

    /**
     * Check whether the SQL is an INSERT (including CTE-prefixed INSERTs).
     */
    public static function isInsert(string $sql): bool {
        return self::classify($sql) === self::INSERT;
    }

    /**
     * Check whether the SQL is an UPDATE (including CTE-prefixed UPDATEs).
     */
    public static function isUpdate(string $sql): bool {
        return self::classify($sql) === self::UPDATE;
    }

    /**
     * Check whether the SQL is a DELETE (including CTE-prefixed DELETEs).
     */
    public static function isDelete(string $sql): bool {
        return self::classify($sql) === self::DELETE;
    }

    /**
     * Check whether the SQL is a SELECT (including CTE-prefixed SELECTs).
     */
    public static function isSelect(string $sql): bool {
        return self::classify($sql) === self::SELECT;
    }

    /**
     * Check whether the SQL is a result-producing statement (SELECT or INSERT...RETURNING).
     *
     * Note: This does NOT parse the SQL to detect RETURNING. It only checks the
     * statement type. Callers should use driver-specific logic for RETURNING detection.
     */
    public static function isResultProducing(string $sql): bool {
        $type = self::classify($sql);
        return $type === self::SELECT;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Check whether $upper starts with $keyword followed by whitespace, end-of-string,
     * or a newline (for multi-line SQL).
     */
    private static function startsWithKeyword(string $upper, string $keyword): bool {
        $len = strlen($keyword);
        if (strlen($upper) < $len) {
            return false;
        }
        if (substr($upper, 0, $len) !== $keyword) {
            return false;
        }
        // Must be followed by whitespace, end-of-string, or newline
        if (strlen($upper) === $len) {
            return true;
        }
        $next = $upper[$len];
        return $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r";
    }

    /**
     * Strip single-line (--) and block (/* ... * /) comments from SQL.
     * Preserves whitespace structure so keyword positions remain valid.
     */
    private static function stripComments(string $sql): string {
        $len = strlen($sql);
        $out = '';
        $i = 0;

        while ($i < $len) {
            // Single-line comment: --
            if ($i + 1 < $len && $sql[$i] === '-' && $sql[$i + 1] === '-') {
                // Skip until end of line
                $i += 2;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    // Replace with space to preserve positions
                    $out .= ' ';
                    $i++;
                }
                // Include the newline
                if ($i < $len) {
                    $out .= $sql[$i];
                    $i++;
                }
                continue;
            }

            // Block comment: /* ... */
            if ($i + 1 < $len && $sql[$i] === '/' && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i < $len) {
                    if ($i + 1 < $len && $sql[$i] === '*' && $sql[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    // Replace with space to preserve positions
                    if ($sql[$i] === "\n" || $sql[$i] === "\r") {
                        $out .= $sql[$i];
                    } else {
                        $out .= ' ';
                    }
                    $i++;
                }
                continue;
            }

            $out .= $sql[$i];
            $i++;
        }

        return $out;
    }

    /**
     * Given a CTE-prefixed SQL (WITH ...), find the first DML keyword after the CTE.
     *
     * Strategy: scan for the first top-level INSERT/UPDATE/DELETE/SELECT/MERGE
     * that appears after the WITH keyword, skipping over parenthesized subqueries.
     *
     * @param string $upperUppercase SQL with comments already stripped
     * @return string|null One of the StatementType constants, or null if not found
     */
    private static function findFirstDmlAfterCTE(string $upper): ?string {
        $len = strlen($upper);
        $depth = 0;
        $i = 4; // skip "WITH"

        while ($i < $len) {
            $ch = $upper[$i];

            if ($ch === '(') {
                $depth++;
                $i++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                $i++;
                continue;
            }

            // Only look for keywords at depth 0 (outside parenthesized subqueries)
            if ($depth === 0) {
                $remaining = substr($upper, $i);

                if (self::startsWithKeyword($remaining, 'INSERT')) {
                    return self::INSERT;
                }
                if (self::startsWithKeyword($remaining, 'UPDATE')) {
                    return self::UPDATE;
                }
                if (self::startsWithKeyword($remaining, 'DELETE')) {
                    return self::DELETE;
                }
                if (self::startsWithKeyword($remaining, 'SELECT')) {
                    return self::SELECT;
                }
            }

            $i++;
        }

        return null;
    }
}