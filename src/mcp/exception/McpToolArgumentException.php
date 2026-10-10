<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\exception;

use dev\winterframework\exception\WinterException;

/**
 * Thrown by a tool method for an argument the model can fix. The message
 * is returned to the model as an isError result, so it must not contain
 * secrets or echo large inputs.
 */
class McpToolArgumentException extends WinterException {
}
