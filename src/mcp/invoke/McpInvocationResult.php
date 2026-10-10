<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\invoke;

/**
 * Outcome of one tool invocation, before it is shaped into a tools/call
 * result.
 */
final class McpInvocationResult {

    public const OK = 'ok';
    /** The model can see the message and try again. */
    public const TOOL_ERROR = 'tool_error';
    /** Refused by an interceptor or a 401/403; the model sees "not permitted". */
    public const DENIED = 'denied';
    /** Anything else; the model sees "internal error", details go to the log. */
    public const INTERNAL = 'internal';

    private function __construct(
        public readonly string $status,
        public readonly mixed $value = null,
        public readonly ?string $text = null,
        public readonly string $message = '',
    ) {
    }

    /**
     * @param mixed $value JSON-ready value (already converted)
     * @param string|null $text text content to send as is (e.g. a REST body); derived from $value when null
     */
    public static function ok(mixed $value, ?string $text = null): self {
        return new self(self::OK, $value, $text);
    }

    public static function toolError(string $message): self {
        return new self(self::TOOL_ERROR, message: $message);
    }

    public static function denied(): self {
        return new self(self::DENIED, message: 'not permitted');
    }

    public static function internal(): self {
        return new self(self::INTERNAL, message: 'internal error');
    }

    public function isOk(): bool {
        return $this->status === self::OK;
    }
}
