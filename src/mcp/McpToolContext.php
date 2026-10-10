<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\web\http\HttpRequest;

/**
 * Per-call information a tool method or McpToolInterceptor can read.
 * Injected into a service tool when it declares a McpToolContext
 * parameter; never part of the input schema.
 */
final class McpToolContext {

    private ?string $outcome = null;

    /**
     * @param array<string, mixed> $arguments the call's arguments, already validated
     */
    public function __construct(
        private readonly McpToolDefinition $tool,
        private readonly string|int|null $requestId,
        private readonly HttpRequest $request,
        private readonly array $arguments = [],
    ) {
    }

    /**
     * The call's arguments as the client sent them (after validation).
     * Read-only. Mind what you log: arguments can carry user data.
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array {
        return $this->arguments;
    }

    public function getArgument(string $name, mixed $default = null): mixed {
        return array_key_exists($name, $this->arguments) ? $this->arguments[$name] : $default;
    }

    /**
     * How the call ended, for McpToolInterceptor::afterCall(): "ok",
     * "tool_error" (an isError result the model sees: invalid arguments,
     * McpToolArgumentException, a 4xx), "denied" or "internal"; null while
     * the call is still running.
     */
    public function getOutcome(): ?string {
        return $this->outcome;
    }

    /** @internal set by McpServer before afterCall() */
    public function setOutcome(string $outcome): void {
        $this->outcome = $outcome;
    }

    public function isOk(): bool {
        return $this->outcome === McpInvocationResult::OK;
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
