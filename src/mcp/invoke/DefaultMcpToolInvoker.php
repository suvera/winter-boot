<?php
declare(strict_types=1);

namespace dev\winterframework\mcp\invoke;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\io\stream\BufferedHttpOutputStream;
use dev\winterframework\mcp\exception\McpToolArgumentException;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolDefinition;
use dev\winterframework\mcp\McpValueCodec;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\HttpRequestDispatcher;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\InternalHttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use ReflectionMethod;
use Throwable;

/**
 * REST tools: an in-process request through the normal dispatcher
 * (argument binding, interceptors, AOP, error controller, renderer), so
 * a tool call behaves exactly like the HTTP call it wraps.
 * Service tools: the method is called on the bean, so its AOP applies.
 */
class DefaultMcpToolInvoker implements McpToolInvoker {
    use Wlf4p;

    /** Headers that belong to the MCP transport, not to the wrapped call. */
    private const TRANSPORT_HEADERS = [
        'content-type', 'content-length', 'accept', 'origin', 'mcp-protocol-version', 'mcp-session-id',
        'last-event-id', 'transfer-encoding', 'connection', 'expect', 'te', 'upgrade', 'keep-alive',
    ];

    /** Error bodies longer than this are cut before they reach the model. */
    public const MAX_ERROR_TEXT = 4000;

    public function __construct(
        private readonly ApplicationContext $appCtx,
        private readonly HttpRequestDispatcher $dispatcher,
        private readonly string $contextPath,
    ) {
    }

    public function invoke(McpToolDefinition $tool, array $arguments, McpToolContext $ctx): McpInvocationResult {
        return $tool->isRest()
            ? $this->invokeRest($tool, $arguments, $ctx)
            : $this->invokeService($tool, $arguments, $ctx);
    }

    // ------------------------------------------------------------------- REST

    private function invokeRest(McpToolDefinition $tool, array $arguments, McpToolContext $ctx): McpInvocationResult {
        $request = self::buildRestRequest($tool, $arguments, $ctx->getRequest(), $this->contextPath);
        $stream = new BufferedHttpOutputStream();
        $response = new ResponseEntity();
        $response->setOutputStream($stream);

        $this->dispatcher->dispatch($request, $response);

        return self::mapRestResponse(
            $tool,
            $response->getStatus()->getValue(),
            $response->getStatus()->getReasonPhrase(),
            (string)($response->getHeaders()->getContentType() ?? ''),
            $stream->getContents()
        );
    }

    /**
     * The in-process request for a REST tool: path arguments in the URI
     * (under server.context-path), query/post arguments as parameters,
     * the body argument as the body, and the caller's headers and cookies
     * minus the MCP transport headers.
     */
    public static function buildRestRequest(
        McpToolDefinition $tool,
        array $arguments,
        HttpRequest $outer,
        string $contextPath
    ): InternalHttpRequest {
        $pathValues = [];
        $query = [];
        $post = [];
        $body = '';
        $bodyIsString = false;
        foreach ($tool->bindings as $b) {
            if (!array_key_exists($b['arg'], $arguments) || $arguments[$b['arg']] === null) {
                continue;
            }
            $value = $arguments[$b['arg']];
            switch ($b['bind']) {
                case McpToolDefinition::BIND_PATH:
                    $pathValues[$b['arg']] = rawurlencode(self::scalarString($value));
                    break;
                case McpToolDefinition::BIND_QUERY:
                    $query[$b['arg']] = self::scalarString($value);
                    break;
                case McpToolDefinition::BIND_POST:
                    $post[$b['arg']] = self::scalarString($value);
                    break;
                case McpToolDefinition::BIND_BODY:
                    if (is_string($value) && ($tool->inputSchema['properties'][$b['arg']]['type'] ?? null) === 'string') {
                        $body = $value;
                        $bodyIsString = true;
                    } else {
                        $body = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    break;
            }
        }

        $uri = self::expandTemplate((string)$tool->routeTemplate, $pathValues);
        $prefix = trim($contextPath, '/');
        $uri = '/' . ($prefix !== '' ? $prefix . '/' : '') . $uri;

        $consumes = $tool->mapping?->consumes;
        $contentType = '';
        if ($body !== '' || $bodyIsString) {
            $contentType = !empty($consumes) ? (string)$consumes[0]
                : ($bodyIsString ? MediaType::TEXT_PLAIN : MediaType::APPLICATION_JSON);
        } elseif ($post) {
            $contentType = MediaType::APPLICATION_FORM_URLENCODED;
        }

        $headers = new HttpHeaders();
        foreach ($outer->getHeaders()->getAll() as $name => $values) {
            if (in_array(strtolower((string)$name), self::TRANSPORT_HEADERS, true)) {
                continue;
            }
            foreach ((array)$values as $v) {
                $headers->add((string)$name, (string)$v);
            }
        }
        $headers->set(HttpHeaders::ACCEPT, MediaType::APPLICATION_JSON);

        return new InternalHttpRequest(
            $outer,
            (string)$tool->httpMethod,
            $uri,
            $query,
            $post,
            $body,
            $contentType,
            $headers,
        );
    }

    /** Fills "{name}" segments of a route template; values are already URL-encoded. */
    public static function expandTemplate(string $template, array $values): string {
        $parts = $template === '' ? [] : explode('/', trim($template, '/'));
        foreach ($parts as $i => $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z_0-9]*)\}$/', $part, $m)) {
                $parts[$i] = $values[$m[1]] ?? '';
            }
        }
        return implode('/', $parts);
    }

    /** Scalar argument as the string an HTTP client would send. */
    public static function scalarString(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            // json_encode keeps "2.0" (route regex for floats needs the dot) and ignores locale.
            return (string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        }
        return (string)$value;
    }

    /**
     * Maps the rendered response of a REST tool:
     * 2xx: the body (decoded when JSON); 401/403: denied;
     * other 4xx: tool error with status and (truncated) body, which the
     * endpoint already meant for its caller; 5xx: internal error.
     */
    public static function mapRestResponse(
        McpToolDefinition $tool,
        int $status,
        string $reason,
        string $contentType,
        string $body
    ): McpInvocationResult {
        if ($status === 401 || $status === 403) {
            return McpInvocationResult::denied();
        }
        if ($status >= 500 || $status < 200) {
            self::logWarning('MCP tool "' . $tool->name . '" failed: endpoint answered HTTP ' . $status);
            return McpInvocationResult::internal();
        }
        if ($status >= 300) {
            $text = 'HTTP ' . $status . ' ' . $reason;
            $clean = self::cleanText($body);
            if ($clean !== '') {
                $text .= "\n" . $clean;
            }
            return McpInvocationResult::toolError($text);
        }

        $isJson = str_contains(strtolower($contentType), 'json');
        if ($isJson && trim($body) !== '') {
            try {
                return McpInvocationResult::ok(json_decode($body, false, 512, JSON_THROW_ON_ERROR), $body);
            } catch (\JsonException) {
                self::logWarning('MCP tool "' . $tool->name . '": endpoint returned invalid JSON');
                return McpInvocationResult::internal();
            }
        }
        if (trim($body) === '') {
            return McpInvocationResult::ok(null, 'ok');
        }
        return McpInvocationResult::ok($body, $body);
    }

    private static function cleanText(string $text): string {
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (strlen($text) > self::MAX_ERROR_TEXT) {
            $text = mb_strcut($text, 0, self::MAX_ERROR_TEXT) . '...';
        }
        return trim($text);
    }

    // ---------------------------------------------------------------- service

    private function invokeService(McpToolDefinition $tool, array $arguments, McpToolContext $ctx): McpInvocationResult {
        $method = new ReflectionMethod($tool->className, $tool->methodName);
        try {
            $args = self::bindServiceArguments($tool, $arguments, $ctx, $method);
        } catch (McpToolArgumentException $e) {
            return McpInvocationResult::toolError($e->getMessage());
        }

        $bean = $this->appCtx->beanByClass($tool->className);
        try {
            $out = $bean->{$tool->methodName}(...$args);
        } catch (McpToolArgumentException $e) {
            return McpInvocationResult::toolError($e->getMessage());
        } catch (McpToolDeniedException $e) {
            self::logInfo('MCP tool "' . $tool->name . '" denied by the tool: ' . $e->getMessage());
            return McpInvocationResult::denied();
        } catch (HttpRestException $e) {
            $code = $e->getStatus()->getValue();
            if ($code === 401 || $code === 403) {
                return McpInvocationResult::denied();
            }
            if ($code >= 400 && $code < 500) {
                return McpInvocationResult::toolError(self::cleanText($e->getMessage()));
            }
            self::logException($e, 'MCP tool "' . $tool->name . '" failed. ');
            return McpInvocationResult::internal();
        } catch (Throwable $e) {
            self::logException($e, 'MCP tool "' . $tool->name . '" failed. ');
            return McpInvocationResult::internal();
        }

        try {
            return McpInvocationResult::ok(McpValueCodec::toJson($out));
        } catch (Throwable $e) {
            self::logWarning('MCP tool "' . $tool->name . '": result is not JSON-encodable: ' . $e->getMessage());
            return McpInvocationResult::internal();
        }
    }

    /**
     * Positional arguments for a service tool, in parameter order.
     * @throws McpToolArgumentException
     */
    public static function bindServiceArguments(
        McpToolDefinition $tool,
        array $arguments,
        McpToolContext $ctx,
        ReflectionMethod $method
    ): array {
        $params = [];
        foreach ($method->getParameters() as $p) {
            $params[$p->getName()] = $p;
        }
        $args = [];
        foreach ($tool->bindings as $b) {
            $param = $params[$b['param']];
            switch ($b['bind']) {
                case McpToolDefinition::BIND_HTTP_REQUEST:
                    $args[] = $ctx->getRequest();
                    break;
                case McpToolDefinition::BIND_CONTEXT:
                    $args[] = $ctx;
                    break;
                default:
                    if (array_key_exists($b['arg'], $arguments)) {
                        $args[] = McpValueCodec::toPhp($arguments[$b['arg']], $param->getType(), $b['arg']);
                    } elseif ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } elseif ($param->allowsNull()) {
                        $args[] = null;
                    } else {
                        throw new McpToolArgumentException($b['arg'] . ': is required');
                    }
            }
        }
        return $args;
    }
}
