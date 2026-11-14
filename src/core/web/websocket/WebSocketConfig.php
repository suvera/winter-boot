<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\websocket;

use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\exception\WinterException;

/**
 * `server.websocket.*` settings, read once at startup.
 *
 * | key | default | |
 * |---|---|---|
 * | enabled | auto | true serves WebSockets even without a handler; false never does |
 * | allowedOrigins | [] | browser Origins allowed to connect; [] = same host only; "*" = any |
 * | maxMessageSize | 1048576 | largest incoming message (fragments joined) in bytes; bigger ones close with 1009 |
 * | idleTimeout | 90 | seconds without any frame (pongs count) before closing; 0 = never |
 * | heartbeatInterval | 30 | seconds between server pings; 0 = no pings |
 * | maxConcurrentMessages | 32 | messages of one connection handled at once; more closes with 1008 |
 * | compression | false | permessage-deflate (Swoole websocket_compression) |
 */
final class WebSocketConfig {
    public const PREFIX = 'server.websocket.';
    /** Swoole's package_max_length default: the largest frame it accepts. */
    public const SWOOLE_DEFAULT_PACKAGE_MAX = 2 * 1024 * 1024;

    /**
     * @param string[] $allowedOrigins
     */
    public function __construct(
        public readonly array $allowedOrigins = [],
        public readonly int $maxMessageSize = 1048576,
        public readonly float $idleTimeout = 90.0,
        public readonly float $heartbeatInterval = 30.0,
        public readonly int $maxConcurrentMessages = 32,
        public readonly bool $compression = false
    ) {
    }

    public static function fromProperties(PropertyContext $prop): self {
        $origins = $prop->get(self::PREFIX . 'allowedOrigins', []);
        if (is_string($origins)) {
            $origins = explode(',', $origins);
        }
        $origins = array_values(array_filter(
            array_map(fn($o) => is_scalar($o) ? trim((string)$o) : '', is_array($origins) ? $origins : []),
            fn(string $o) => $o !== ''
        ));
        $config = new self(
            $origins,
            $prop->getInt(self::PREFIX . 'maxMessageSize', 1048576),
            $prop->getFloat(self::PREFIX . 'idleTimeout', 90.0),
            $prop->getFloat(self::PREFIX . 'heartbeatInterval', 30.0),
            $prop->getInt(self::PREFIX . 'maxConcurrentMessages', 32),
            $prop->getBool(self::PREFIX . 'compression', false)
        );
        if ($config->maxMessageSize < 1) {
            throw new WinterException('server.websocket.maxMessageSize must be a positive number of bytes');
        }
        if ($config->maxConcurrentMessages < 1) {
            throw new WinterException('server.websocket.maxConcurrentMessages must be at least 1');
        }
        return $config;
    }

    /**
     * WebSockets are served when server.websocket.enabled is true, or when
     * it is unset and the application has a #[WebSocketController].
     */
    public static function isEnabled(PropertyContext $prop, bool $hasHandlers): bool {
        if ($prop->has(self::PREFIX . 'enabled')) {
            return $prop->getBool(self::PREFIX . 'enabled', false);
        }
        return $hasHandlers;
    }

    /**
     * Swoole settings for a WebSocket server, merged over server.swoole.*:
     * control frames reach the framework (close codes for onClose(), pings
     * and pongs count as activity). Swoole drops frames larger than
     * package_max_length before the framework sees them, and that setting
     * also caps HTTP bodies, so it is never lowered: a maxMessageSize above
     * it fails startup instead of loosening the HTTP limit silently.
     *
     * @param array<string, mixed> $serverArgs the configured server.swoole.* settings
     * @return array<string, mixed>
     */
    public function swooleSettings(array $serverArgs): array {
        $packageMax = (int)($serverArgs['package_max_length'] ?? self::SWOOLE_DEFAULT_PACKAGE_MAX);
        if ($this->maxMessageSize > $packageMax) {
            throw new WinterException(
                "server.websocket.maxMessageSize ({$this->maxMessageSize}) exceeds server.swoole.package_max_length"
                . " ($packageMax): Swoole would drop such frames. Raise package_max_length (it also caps HTTP bodies)"
                . ' or lower maxMessageSize.'
            );
        }
        $settings = [
            'open_websocket_close_frame' => true,
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ];
        if ($this->compression && !array_key_exists('websocket_compression', $serverArgs)) {
            $settings['websocket_compression'] = true;
        }
        return $settings;
    }
}
