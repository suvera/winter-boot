<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\web\http\HttpRequest;

/**
 * Per-call information a tool method or McpToolInterceptor can read.
 * Injected into a service tool when it declares a McpToolContext
 * parameter; never part of the input schema.
 */
final class McpToolContext {

    public function __construct(
        private readonly McpToolDefinition $tool,
        private readonly string|int|null $requestId,
        private readonly HttpRequest $request,
    ) {
    }

    public function getTool(): McpToolDefinition {
        return $this->tool;
    }

    public function getToolName(): string {
        return $this->tool->name;
    }

    /** The JSON-RPC id of the tools/call request. */
    public function getRequestId(): string|int|null {
        return $this->requestId;
    }

    /** The HTTP request to the MCP endpoint (headers, caller address). */
    public function getRequest(): HttpRequest {
        return $this->request;
    }
}
