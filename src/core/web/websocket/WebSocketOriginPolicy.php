<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

/**
 * Cross-site WebSocket hijacking guard: browsers attach cookies to a
 * WebSocket handshake from any site, so the Origin decides whether a
 * cookie-authenticated connection is allowed.
 *
 * - No Origin header: allowed (not a browser; it carries no ambient cookies
 *   of another site).
 * - "null" (sandboxed iframes, file://): never allowed.
 * - allowedOrigins empty: the Origin's host and port must equal the Host
 *   header's (same host).
 * - Otherwise exact match on scheme://host[:port] (case-insensitive,
 *   default ports normalised), or "*" for any origin.
 */
final class WebSocketOriginPolicy {

    /**
     * @param string[] $allowedOrigins
     */
    public static function isAllowed(?string $origin, ?string $host, array $allowedOrigins): bool {
        if ($origin === null) {
            return true;
        }
        $origin = trim($origin);
        $normalised = self::normalise($origin);
        if ($normalised === null) {
            return false;
        }
        if ($allowedOrigins === []) {
            return $host !== null && self::hostPort($normalised) === self::normaliseHost($host, $normalised);
        }
        foreach ($allowedOrigins as $allowed) {
            if ($allowed === '*') {
                return true;
            }
            if (self::normalise($allowed) === $normalised) {
                return true;
            }
        }
        return false;
    }

    /** scheme://host:port in lower case with the default port filled in, or null when unusable. */
    public static function normalise(string $origin): ?string {
        if ($origin === '' || strtolower($origin) === 'null' || preg_match('/[\x00-\x20\x7F]/', $origin)) {
            return null;
        }
        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    private static function hostPort(string $normalised): string {
        return substr($normalised, strpos($normalised, '://') + 3);
    }

    /** The Host header as host:port, taking the default port from the origin's scheme. */
    private static function normaliseHost(string $host, string $normalisedOrigin): ?string {
        $host = strtolower(trim($host));
        if ($host === '' || preg_match('/[\x00-\x20\x7F\/@]/', $host)) {
            return null;
        }
        if (preg_match('/^(\[[0-9a-f:.]+\]|[^:]+)(?::(\d+))?$/', $host, $m) !== 1) {
            return null;
        }
        $defaultPort = str_starts_with($normalisedOrigin, 'https://') ? 443 : 80;
        return $m[1] . ':' . (isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $defaultPort);
    }
}
