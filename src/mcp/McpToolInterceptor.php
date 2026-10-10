<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\web\http\HttpRequest;
use Throwable;

/**
 * Optional per-tool hook. Every #[Component] / #[Service] bean that
 * implements it is applied to every tool, in class-name order.
 *
 * Use it for per-tool authorization, auditing and quotas. Route-level
 * HandlerInterceptors still run for REST tools; service tools have only
 * the /mcp request's interceptors plus this hook.
 */
interface McpToolInterceptor {

    /** False hides the tool from this caller's tools/list. */
    public function isVisible(McpToolDefinition $tool, HttpRequest $request): bool;

    /**
     * Runs before the call. Throw McpToolDeniedException to refuse it;
     * the model then sees "not permitted".
     */
    public function beforeCall(McpToolDefinition $tool, McpToolContext $ctx): void;

    /** Runs after the call, with the failure if there was one. */
    public function afterCall(McpToolDefinition $tool, McpToolContext $ctx, ?Throwable $error): void;
}
