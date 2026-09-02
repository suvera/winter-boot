<?php
declare(strict_types=1);

namespace dev\winterframework\migrations;

/**
 * SqlFileStatementParser
 *
 * Splits the raw contents of a .sql file into individual statements,
 * trying to be reasonably safe across PostgreSQL, MySQL, SQLite,
 * Oracle and SQL Server dumps.
 *
 * Handles:
 *  - line comments: --  (all dialects) and # (MySQL, only at start of line
 *    to avoid colliding with PostgreSQL's "#", "#>", "#>>" operators)
 *  - block comments: /* ... *\/
 *  - single-quoted, double-quoted and `backtick`-quoted content, with
 *    both doubled-quote escaping ('' or "" or ``) and backslash escaping
 *  - PostgreSQL dollar-quoting: $$ ... $$ and $tag$ ... $tag$
 *    (only while the active delimiter is the default ';')
 *  - MySQL `DELIMITER` directive (e.g. DELIMITER $$ ... DELIMITER ;)
 *  - Oracle SQL*Plus "/" on its own line as a block terminator
 *
 * Known limitation: it does NOT track BEGIN/END nesting. A PL/SQL or
 * T-SQL block that keeps the default ';' delimiter (no DELIMITER
 * change, no trailing "/") will still be split at each inner ';'.
 * Correctly handling that in general requires a real per-dialect
 * grammar-aware parser, not a character scanner. Use DELIMITER
 * changes (MySQL) or a trailing "/" (Oracle) to get correct results
 * for those blocks, or post-process/merge known block patterns.
 */
class SqlFileStatementParser {
    private bool $hashCommentsEnabled;
    private bool $dollarQuotingEnabled;
    private bool $backslashEscapesEnabled;

    /**
     * @param bool $hashCommentsEnabled     Treat a line-start '#' as a comment (MySQL style).
     * @param bool $dollarQuotingEnabled    Recognize PostgreSQL $$ / $tag$ quoting.
     * @param bool $backslashEscapesEnabled Treat backslash as an escape char inside strings
     *                                      (true matches MySQL default mode; standard SQL
     *                                      dialects only use doubled quotes to escape, so set
     *                                      this to false if you know the file is pure
     *                                      Postgres/Oracle/SQL Server/SQLite and may contain
     *                                      literal backslashes, e.g. Windows paths, at the end
     *                                      of a string).
     */
    public function __construct(
        bool $hashCommentsEnabled = true,
        bool $dollarQuotingEnabled = true,
        bool $backslashEscapesEnabled = true
    ) {
        $this->hashCommentsEnabled = $hashCommentsEnabled;
        $this->dollarQuotingEnabled = $dollarQuotingEnabled;
        $this->backslashEscapesEnabled = $backslashEscapesEnabled;
    }

    /**
     * @return string[] List of trimmed, non-empty SQL statements.
     */
    public function parse(string $sql): array {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        $delimiter = ';';

        $inLineComment = false;
        $inBlockComment = false;
        $inDollarQuote = false;
        $dollarTag = '';
        $quoteChar = null; // null | ' | " | `
        $atLineStart = true;

        while ($i < $length) {
            $char = $sql[$i];
            $nextChar = ($i + 1 < $length) ? $sql[$i + 1] : '';

            // ---- inside a line comment ----
            if ($inLineComment) {
                $current .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                    $atLineStart = true;
                }
                $i++;
                continue;
            }

            // ---- inside a block comment ----
            if ($inBlockComment) {
                $current .= $char;
                if ($char === '*' && $nextChar === '/') {
                    $current .= $nextChar;
                    $i += 2;
                    $inBlockComment = false;
                    continue;
                }
                $i++;
                continue;
            }

            // ---- inside a dollar-quoted string ----
            if ($inDollarQuote) {
                $current .= $char;
                if ($char === '$') {
                    $closing = '$' . $dollarTag . '$';
                    if (substr($sql, $i, strlen($closing)) === $closing) {
                        $current .= substr($closing, 1);
                        $i += strlen($closing);
                        $inDollarQuote = false;
                        $dollarTag = '';
                        continue;
                    }
                }
                $i++;
                continue;
            }

            // ---- inside a quoted string / identifier ----
            if ($quoteChar !== null) {
                $current .= $char;
                if ($this->backslashEscapesEnabled && $char === '\\' && $i + 1 < $length) {
                    $current .= $nextChar;
                    $i += 2;
                    continue;
                }
                if ($char === $quoteChar) {
                    if ($nextChar === $quoteChar) {
                        // doubled quote = escaped literal quote, stay in string
                        $current .= $nextChar;
                        $i += 2;
                        continue;
                    }
                    $quoteChar = null;
                }
                $i++;
                continue;
            }

            // ---- normal code state ----

            if ($char === "\n") {
                $current .= $char;
                $atLineStart = true;
                $i++;
                continue;
            }

            if ($char === ' ' || $char === "\t" || $char === "\r") {
                $current .= $char;
                $i++;
                continue;
            }

            // MySQL DELIMITER directive (only meaningful at the start of a statement)
            if (
                $atLineStart && trim($current) === ''
                && preg_match('/\GDELIMITER[ \t]+/i', $sql, $m, 0, $i)
            ) {
                $lineEnd = strpos($sql, "\n", $i);
                if ($lineEnd === false) {
                    $lineEnd = $length;
                }
                $directiveLine = substr($sql, $i, $lineEnd - $i);
                $newDelimiter = trim(substr($directiveLine, strlen($m[0])));
                if ($newDelimiter !== '') {
                    $delimiter = $newDelimiter;
                }
                $i = $lineEnd;
                $current = '';
                $atLineStart = true;
                continue;
            }

            // Oracle SQL*Plus "/" terminator on its own line
            if ($atLineStart && $char === '/' && trim($current) !== '') {
                $lineEnd = strpos($sql, "\n", $i);
                $restOfLine = ($lineEnd === false)
                    ? substr($sql, $i + 1)
                    : substr($sql, $i + 1, $lineEnd - $i - 1);
                if (trim($restOfLine) === '') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    $i = ($lineEnd === false) ? $length : $lineEnd + 1;
                    $atLineStart = true;
                    continue;
                }
            }

            // '--' line comment (all dialects)
            if ($char === '-' && $nextChar === '-') {
                $inLineComment = true;
                $current .= $char . $nextChar;
                $i += 2;
                $atLineStart = false;
                continue;
            }

            // '#' line comment (MySQL), only recognized at start of line
            if ($this->hashCommentsEnabled && $char === '#' && $atLineStart) {
                $inLineComment = true;
                $current .= $char;
                $i++;
                continue;
            }

            // '/* ... */' block comment
            if ($char === '/' && $nextChar === '*') {
                $inBlockComment = true;
                $current .= $char . $nextChar;
                $i += 2;
                $atLineStart = false;
                continue;
            }

            // quoted string / identifier start
            if ($char === "'" || $char === '"' || $char === '`') {
                $quoteChar = $char;
                $current .= $char;
                $i++;
                $atLineStart = false;
                continue;
            }

            // PostgreSQL dollar-quote start ($$ or $tag$), only while delimiter is default ';'
            if (
                $this->dollarQuotingEnabled && $delimiter === ';' && $char === '$'
                && preg_match('/\G\$([A-Za-z0-9_]*)\$/', $sql, $m, 0, $i)
            ) {
                $inDollarQuote = true;
                $dollarTag = $m[1];
                $current .= $m[0];
                $i += strlen($m[0]);
                $atLineStart = false;
                continue;
            }

            // active delimiter match (default ';' or whatever DELIMITER set it to)
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i += strlen($delimiter);
                $atLineStart = false;
                continue;
            }

            $current .= $char;
            $atLineStart = false;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
