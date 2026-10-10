<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\exception;

use dev\winterframework\exception\WinterException;

/**
 * A #[McpTool] declaration that can't be served. Thrown at boot, so a
 * broken tool stops the application instead of failing at call time.
 */
class McpDefinitionException extends WinterException {
}
