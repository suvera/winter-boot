<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\exception;

use dev\winterframework\exception\WinterException;

/**
 * Thrown by McpToolInterceptor::beforeCall() to refuse a call. The model
 * only ever sees "not permitted"; the message is for the server log.
 */
class McpToolDeniedException extends WinterException {
}
