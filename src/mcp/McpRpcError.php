<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

/** A JSON-RPC error to answer instead of a result. */
final class McpRpcError {

    public function __construct(
        public readonly int $code,
        public readonly string $message,
    ) {
    }
}
