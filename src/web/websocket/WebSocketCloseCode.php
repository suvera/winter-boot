<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

/**
 * WebSocket close codes (RFC 6455 §7.4). Applications may also use their
 * own codes in 4000-4999.
 */
final class WebSocketCloseCode {
    public const NORMAL = 1000;
    /** Server restarting or shutting down: clients should reconnect. */
    public const GOING_AWAY = 1001;
    public const PROTOCOL_ERROR = 1002;
    public const UNSUPPORTED_DATA = 1003;
    /** Never sent: reported to onClose() when no close frame arrived. */
    public const NO_STATUS = 1005;
    /** Never sent: reported to onClose() when the connection dropped. */
    public const ABNORMAL = 1006;
    public const INVALID_PAYLOAD = 1007;
    public const POLICY_VIOLATION = 1008;
    public const MESSAGE_TOO_BIG = 1009;
    public const INTERNAL_ERROR = 1011;
    public const TRY_AGAIN_LATER = 1013;

    private function __construct() {
    }

    /** Whether $code may be sent in a close frame. */
    public static function isSendable(int $code): bool {
        return ($code >= 1000 && $code <= 1003)
            || ($code >= 1007 && $code <= 1014)
            || ($code >= 3000 && $code <= 4999);
    }

    /**
     * Bounded label for metrics: the well-known codes as themselves,
     * application codes as "app", anything else as "other".
     */
    public static function label(int $code): string {
        if (in_array($code, [1000, 1001, 1002, 1003, 1005, 1006, 1007, 1008, 1009, 1011, 1013], true)) {
            return (string)$code;
        }
        return ($code >= 3000 && $code <= 4999) ? 'app' : 'other';
    }
}
