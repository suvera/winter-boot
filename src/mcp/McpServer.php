<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\mcp\exception\McpResourceNotFoundException;
use dev\winterframework\mcp\exception\McpToolArgumentException;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\mcp\invoke\McpToolInvoker;
use dev\winterframework\mcp\schema\McpSchemaValidator;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\type\TypeCast;
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
    /** MCP: resources/read for a URI that doesn't exist. */
    public const RESOURCE_NOT_FOUND = -32002;

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
                'resources/list' => $this->registry->resources() ? $this->listResources($request) : null,
                'resources/templates/list' => $this->registry->resources() ? $this->listResourceTemplates() : null,
                'resources/read' => $this->registry->resources() ? $this->readResource($params, $request) : null,
                'prompts/list' => $this->registry->prompts() ? $this->listPrompts() : null,
                'prompts/get' => $this->registry->prompts() ? $this->getPrompt($params, $request) : null,
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
            $error = self::error($id, $result->code, $result->message);
            if ($result->data !== null) {
                $error['error']['data'] = $result->data;
            }
            return $error;
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
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->serverInfo,
        ];
        if ($this->instructions !== '') {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }

    /** Only what the application declares: tools, resources, prompts. */
    private function capabilities(): array {
        $caps = [];
        if ($this->registry->all()) {
            $caps['tools'] = ['listChanged' => false];
        }
        if ($this->registry->resources()) {
            $caps['resources'] = ['subscribe' => false, 'listChanged' => false];
        }
        if ($this->registry->prompts()) {
            $caps['prompts'] = ['listChanged' => false];
        }
        return $caps ?: ['tools' => ['listChanged' => false]];
    }

    // -------------------------------------------------------------- resources

    private function listResources(HttpRequest $request): array {
        $out = [];
        foreach ($this->registry->resources() as $r) {
            if (!$r->isTemplate()) {
                $out[] = $r->toListEntry();
            }
            if ($r->listMethod === null) {
                continue;
            }
            $listed = $this->callBean($r->className, $r->listMethod, [], $request);
            foreach (is_iterable($listed) ? $listed : [] as $entry) {
                $entry = McpValueCodec::toJson($entry);
                if (!is_array($entry) || !is_string($entry['uri'] ?? null) || !is_string($entry['name'] ?? null)) {
                    self::logWarning('MCP resource list "' . $r->name . '": entry without a string uri and name skipped');
                    continue;
                }
                $item = ['uri' => $entry['uri'], 'name' => $entry['name']];
                foreach (['title', 'description', 'mimeType'] as $k) {
                    if (is_string($entry[$k] ?? null)) {
                        $item[$k] = $entry[$k];
                    }
                }
                $item['mimeType'] ??= $r->mimeType;
                $out[] = $item;
            }
        }
        return ['resources' => $out];
    }

    private function listResourceTemplates(): array {
        $out = [];
        foreach ($this->registry->resources() as $r) {
            if ($r->isTemplate()) {
                $out[] = $r->toListEntry();
            }
        }
        return ['resourceTemplates' => $out];
    }

    private function readResource(array $params, HttpRequest $request): array|McpRpcError {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return new McpRpcError(self::INVALID_PARAMS, 'uri must be a non-empty string');
        }
        $notFound = new McpRpcError(self::RESOURCE_NOT_FOUND, 'Resource not found',
            ['uri' => mb_strcut($uri, 0, 512)]);
        $found = $this->registry->findResource($uri);
        if ($found === null) {
            return $notFound;
        }
        [$resource, $vars] = $found;

        $started = hrtime(true);
        $outcome = 'internal';
        try {
            $method = new \ReflectionMethod($resource->className, $resource->methodName);
            $args = [];
            foreach ($method->getParameters() as $p) {
                $bind = null;
                foreach ($resource->bindings as $b) {
                    if ($b['param'] === $p->getName()) {
                        $bind = $b['bind'];
                    }
                }
                if ($bind === 'httpRequest') {
                    $args[] = $request;
                    continue;
                }
                $type = $p->getType();
                try {
                    $args[] = TypeCast::parseConfigValue(
                        $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed',
                        $vars[$p->getName()]
                    );
                } catch (\UnexpectedValueException) {
                    // "site://x/items/abc" for an int {id}: that resource can't exist.
                    $outcome = 'not_found';
                    return $notFound;
                }
            }
            try {
                $value = $this->callBean($resource->className, $resource->methodName, $args, $request);
            } catch (McpResourceNotFoundException) {
                $outcome = 'not_found';
                return $notFound;
            } catch (McpToolArgumentException $e) {
                $outcome = 'invalid';
                return new McpRpcError(self::INVALID_PARAMS, $e->getMessage());
            } catch (HttpRestException $e) {
                $code = $e->getStatus()->getValue();
                if ($code === 401 || $code === 403 || $code === 404) {
                    $outcome = 'not_found';
                    return $notFound;            // never confirm that a hidden resource exists
                }
                if ($code < 500) {
                    $outcome = 'invalid';
                    return new McpRpcError(self::INVALID_PARAMS, $e->getMessage());
                }
                throw $e;
            }
            if ($value === null) {
                $outcome = 'not_found';
                return $notFound;
            }
            $text = is_string($value)
                ? $value
                : json_encode(McpValueCodec::toJson($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $outcome = 'ok';
            return ['contents' => [['uri' => $uri, 'mimeType' => $resource->mimeType, 'text' => $text]]];
        } finally {
            self::logInfo('MCP resource read', [
                'resource' => $resource->name,
                'outcome' => $outcome,
                'ms' => round((hrtime(true) - $started) / 1e6, 1),
            ]);
        }
    }

    // ---------------------------------------------------------------- prompts

    private function listPrompts(): array {
        return ['prompts' => array_values(array_map(
            fn(McpPromptDefinition $p) => $p->toListEntry(),
            $this->registry->prompts()
        ))];
    }

    private function getPrompt(array $params, HttpRequest $request): array|McpRpcError {
        $name = $params['name'] ?? null;
        $prompt = is_string($name) ? $this->registry->findPrompt($name) : null;
        if ($prompt === null) {
            $shown = is_string($name) && preg_match(McpTool::NAME_REGEX, $name) ? $name : '(invalid name)';
            return new McpRpcError(self::INVALID_PARAMS, 'unknown prompt: ' . $shown);
        }
        $given = $params['arguments'] ?? [];
        if (!is_array($given) || ($given !== [] && array_is_list($given))) {
            return new McpRpcError(self::INVALID_PARAMS, 'arguments must be an object');
        }
        $known = array_column($prompt->arguments, null, 'name');
        foreach ($given as $k => $v) {
            if (!isset($known[$k])) {
                return new McpRpcError(self::INVALID_PARAMS, 'unknown argument "' . (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string)$k) ? $k : '?') . '"');
            }
            if (!is_string($v)) {
                return new McpRpcError(self::INVALID_PARAMS, 'argument "' . $k . '" must be a string');
            }
        }

        $started = hrtime(true);
        $outcome = 'internal';
        try {
            $method = new \ReflectionMethod($prompt->className, $prompt->methodName);
            $args = [];
            foreach ($method->getParameters() as $p) {
                $pname = $p->getName();
                if (!isset($known[$pname])) {
                    $args[] = $request;             // the only other binding: HttpRequest
                    continue;
                }
                if (array_key_exists($pname, $given)) {
                    $args[] = $given[$pname];
                } elseif ($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } elseif ($p->allowsNull()) {
                    $args[] = null;
                } else {
                    $outcome = 'invalid';
                    return new McpRpcError(self::INVALID_PARAMS, 'argument "' . $pname . '" is required');
                }
            }
            try {
                $value = $this->callBean($prompt->className, $prompt->methodName, $args, $request);
            } catch (McpToolArgumentException $e) {
                $outcome = 'invalid';
                return new McpRpcError(self::INVALID_PARAMS, $e->getMessage());
            }
            $messages = self::promptMessages($value);
            if ($messages === null) {
                self::logWarning('MCP prompt "' . $prompt->name . '" returned neither a string nor a list of'
                    . ' [role, text] messages');
                return new McpRpcError(self::INTERNAL_ERROR, 'internal error');
            }
            $outcome = 'ok';
            $result = [];
            if ($prompt->description !== '') {
                $result['description'] = $prompt->description;
            }
            $result['messages'] = $messages;
            return $result;
        } finally {
            self::logInfo('MCP prompt get', [
                'prompt' => $prompt->name,
                'outcome' => $outcome,
                'ms' => round((hrtime(true) - $started) / 1e6, 1),
            ]);
        }
    }

    /** A string is one user message; a list holds ['role' => user|assistant, 'text' => ...] items. */
    public static function promptMessages(mixed $value): ?array {
        if (is_string($value)) {
            return [['role' => 'user', 'content' => ['type' => 'text', 'text' => $value]]];
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }
        $out = [];
        foreach ($value as $m) {
            $role = is_array($m) ? ($m['role'] ?? null) : null;
            $text = is_array($m) ? ($m['text'] ?? null) : null;
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($text)) {
                return null;
            }
            $out[] = ['role' => $role, 'content' => ['type' => 'text', 'text' => $text]];
        }
        return $out;
    }

    /**
     * Calls a bean method. $args are positional; with $args === [] and a
     * listMethod, an HttpRequest parameter (the only one allowed) is injected.
     */
    private function callBean(string $class, string $method, array $args, HttpRequest $request): mixed {
        if ($this->appCtx === null) {
            throw new \LogicException('McpServer needs an ApplicationContext for resources and prompts');
        }
        $bean = $this->appCtx->beanByClass($class);
        if ($args === []) {
            $ref = new \ReflectionMethod($class, $method);
            foreach ($ref->getParameters() as $p) {
                $t = $p->getType();
                $args[] = ($t instanceof \ReflectionNamedType && $t->getName() === HttpRequest::class)
                    ? $request
                    : ($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null);
            }
        }
        return $bean->{$method}(...$args);
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
        $ctx = new McpToolContext($tool, $id, $request, $arguments);
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
            $ctx->setOutcome($outcome);
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
