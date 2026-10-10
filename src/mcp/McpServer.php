<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\mcp\invoke\McpToolInvoker;
use dev\winterframework\mcp\schema\McpSchemaValidator;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use Throwable;

/**
 * Model Context Protocol message handling (JSON-RPC 2.0), stateless:
 * no sessions, every request stands alone. Transport checks live in
 * McpController; this class only sees parsed messages.
 *
 * Logs name the tool, outcome and duration; never arguments or results.
 */
class McpServer {
    use Wlf4p;

    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    public const LATEST_VERSION = '2025-11-25';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    private bool $metricsRegistered = false;

    /**
     * @param array{name: string, version: string, title?: string} $serverInfo
     */
    public function __construct(
        private readonly McpToolRegistry $registry,
        private readonly McpToolInvoker $invoker,
        private readonly array $serverInfo,
        private readonly string $instructions = '',
        private readonly ?ApplicationContext $appCtx = null,
        private ?PrometheusMetricRegistry $metrics = null,
    ) {
    }

    public function getRegistry(): McpToolRegistry {
        return $this->registry;
    }

    /**
     * Handles one JSON-RPC message. Returns the response, or null when
     * nothing is to be answered (notifications, client responses).
     */
    public function handle(mixed $message, HttpRequest $request): ?array {
        if (!is_array($message) || array_is_list($message)) {
            return self::error(null, self::INVALID_REQUEST, 'invalid JSON-RPC 2.0 message');
        }
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return self::error(null, self::INVALID_REQUEST, 'id must be a string or an integer');
        }
        if (($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error($hasId ? $id : null, self::INVALID_REQUEST, 'jsonrpc must be "2.0"');
        }
        if (!isset($message['method'])) {
            // A response to a server request; this server sends none.
            return null;
        }
        if (!is_string($message['method'])) {
            return self::error($hasId ? $id : null, self::INVALID_REQUEST, 'method must be a string');
        }
        if (!$hasId) {
            // notifications/initialized, notifications/cancelled, ...: nothing to do.
            return null;
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return self::error($id, self::INVALID_PARAMS, 'params must be an object');
        }

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->listTools($request)],
                'tools/call' => $this->callTool($id, $params, $request),
                default => null,
            };
        } catch (Throwable $e) {
            self::logException($e, 'MCP request failed. ');
            return self::error($id, self::INTERNAL_ERROR, 'internal error');
        }
        if ($result === null) {
            return self::error($id, self::METHOD_NOT_FOUND, 'method not found');
        }
        if ($result instanceof McpRpcError) {
            return self::error($id, $result->code, $result->message);
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error(string|int|null $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** The version answered for a requested one: echoed when supported, else the latest. */
    public static function negotiateVersion(mixed $requested): string {
        return is_string($requested) && in_array($requested, self::SUPPORTED_VERSIONS, true)
            ? $requested
            : self::LATEST_VERSION;
    }

    private function initialize(array $params): array {
        $result = [
            'protocolVersion' => self::negotiateVersion($params['protocolVersion'] ?? null),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => $this->serverInfo,
        ];
        if ($this->instructions !== '') {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }

    /** @return list<array> */
    private function listTools(HttpRequest $request): array {
        $out = [];
        foreach ($this->registry->all() as $tool) {
            if ($this->isVisible($tool, $request)) {
                $out[] = $tool->toListEntry();
            }
        }
        return $out;
    }

    private function isVisible(McpToolDefinition $tool, HttpRequest $request): bool {
        foreach ($this->interceptors() as $interceptor) {
            if (!$interceptor->isVisible($tool, $request)) {
                return false;
            }
        }
        return true;
    }

    /** @return McpToolInterceptor[] */
    private function interceptors(): array {
        return $this->registry->interceptors($this->appCtx);
    }

    private function callTool(string|int $id, array $params, HttpRequest $request): array|McpRpcError {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? $this->registry->find($name) : null;
        if ($tool === null || !$this->isVisible($tool, $request)) {
            // Hidden tools answer exactly like unknown ones.
            $shown = is_string($name) && preg_match(McpTool::NAME_REGEX, $name) ? $name : '(invalid name)';
            return new McpRpcError(self::INVALID_PARAMS, 'unknown tool: ' . $shown);
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return new McpRpcError(self::INVALID_PARAMS, 'arguments must be an object');
        }

        $started = hrtime(true);
        $ctx = new McpToolContext($tool, $id, $request);
        $outcome = McpInvocationResult::INTERNAL;
        $error = null;
        try {
            $invalid = McpSchemaValidator::validate($arguments, $tool->inputSchema);
            if ($invalid !== null) {
                $outcome = McpInvocationResult::TOOL_ERROR;
                return self::toolErrorResult($invalid);
            }

            try {
                foreach ($this->interceptors() as $interceptor) {
                    $interceptor->beforeCall($tool, $ctx);
                }
            } catch (McpToolDeniedException $e) {
                $outcome = McpInvocationResult::DENIED;
                $error = $e;
                self::logInfo('MCP tool call denied', ['tool' => $tool->name, 'reason' => $e->getMessage()]);
                return self::toolErrorResult('not permitted');
            }

            $result = $this->invoker->invoke($tool, $arguments, $ctx);
            $outcome = $result->status;
            if (!$result->isOk()) {
                return self::toolErrorResult($result->message);
            }
            $shaped = self::shapeResult($tool, $result);
            if ($shaped === null) {
                $outcome = McpInvocationResult::INTERNAL;
                return self::toolErrorResult('internal error');
            }
            return $shaped;
        } catch (Throwable $e) {
            $error = $e;
            $outcome = McpInvocationResult::INTERNAL;
            self::logException($e, 'MCP tool "' . $tool->name . '" failed. ');
            return self::toolErrorResult('internal error');
        } finally {
            $ms = (hrtime(true) - $started) / 1e6;
            foreach ($this->interceptors() as $interceptor) {
                try {
                    $interceptor->afterCall($tool, $ctx, $error);
                } catch (Throwable $e) {
                    self::logException($e, 'McpToolInterceptor::afterCall failed. ');
                }
            }
            $this->record($tool->name, $outcome, $ms);
            self::logInfo('MCP tool call', [
                'tool' => $tool->name,
                'outcome' => $outcome,
                'ms' => round($ms, 1),
            ]);
        }
    }

    /**
     * Builds the tools/call result: text content always; structuredContent
     * when the tool has an output schema, checked against it. Returns
     * null when the result breaks the declared schema (logged).
     */
    public static function shapeResult(McpToolDefinition $tool, McpInvocationResult $result): ?array {
        try {
            $json = json_encode($result->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            self::logWarning('MCP tool "' . $tool->name . '": result is not JSON-encodable');
            return null;
        }
        $text = $result->text ?? ($result->value === null ? 'ok' : $json);
        $out = ['content' => [['type' => 'text', 'text' => $text]]];

        if ($tool->outputSchema !== null && $tool->outputWrap !== McpToolDefinition::WRAP_NONE) {
            $asArray = json_decode($json, true);
            $asObject = json_decode($json, false);
            [$check, $structured] = match ($tool->outputWrap) {
                McpToolDefinition::WRAP_ITEMS => [['items' => $asArray], (object)['items' => $asObject]],
                McpToolDefinition::WRAP_RESULT => [['result' => $asArray], (object)['result' => $asObject]],
                default => [$asArray, $asObject],
            };
            if (!$structured instanceof \stdClass && $structured !== []) {
                self::logWarning('MCP tool "' . $tool->name . '": result is not a JSON object as its outputSchema says');
                return null;
            }
            $invalid = McpSchemaValidator::validate($check, $tool->outputSchema);
            if ($invalid !== null) {
                // Path and expected type only; never the value.
                self::logWarning('MCP tool "' . $tool->name . '": result does not match its outputSchema: ' . $invalid);
                return null;
            }
            $out['structuredContent'] = $structured === [] ? new \stdClass() : $structured;
        }
        $out['isError'] = false;
        return $out;
    }

    public static function toolErrorResult(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    private function record(string $tool, string $outcome, float $ms): void {
        if ($this->metrics === null && $this->appCtx?->hasBeanByClass(PrometheusMetricRegistry::class)) {
            // Resolved on first use: the bean may not exist yet when the endpoint is built.
            $this->metrics = $this->appCtx->beanByClass(PrometheusMetricRegistry::class);
        }
        if ($this->metrics === null) {
            return;
        }
        try {
            if (!$this->metricsRegistered) {
                $this->metrics->getOrRegisterCounter('mcp_tool_calls', 'MCP tool calls', ['tool', 'outcome']);
                $this->metrics->getOrRegisterHistogram(
                    'mcp_tool_duration',
                    'MCP tool call duration (seconds)',
                    ['tool'],
                    [0.01, 0.1, 1, 5]
                );
                $this->metricsRegistered = true;
            }
            // Tool names come from code (bounded); unknown names never reach here.
            $this->metrics->incr('mcp_tool_calls', [$tool, $outcome]);
            $this->metrics->observe('mcp_tool_duration', $ms / 1000, [$tool]);
        } catch (Throwable $e) {
            self::logException($e, 'MCP metrics failed. ');
        }
    }
}
