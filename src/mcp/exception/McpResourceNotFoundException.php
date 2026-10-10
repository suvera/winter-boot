<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\exception;

use dev\winterframework\exception\WinterException;

/**
 * Thrown by a #[McpResource] method for a URI that doesn't exist (or that
 * this caller may not see). Answered with the MCP "resource not found"
 * error (-32002), naming only the requested URI.
 */
class McpResourceNotFoundException extends WinterException {
}
