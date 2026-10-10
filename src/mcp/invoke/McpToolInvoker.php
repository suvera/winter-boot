<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\invoke;

use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolDefinition;

/**
 * Runs one validated tool call. The default implementation routes REST
 * tools through the dispatcher and calls service tools on their bean.
 */
interface McpToolInvoker {

    /** @param array<string, mixed> $arguments already validated against the input schema */
    public function invoke(McpToolDefinition $tool, array $arguments, McpToolContext $ctx): McpInvocationResult;
}
