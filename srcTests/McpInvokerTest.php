<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/fixtures/mcp/Sub/ImportedDto.php';
require_once __DIR__ . '/fixtures/mcp/McpFixtures.php';
require_once __DIR__ . '/McpServerTest.php';

use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\core\web\MatchedRequestMapping;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\io\metrics\prometheus\DefaultPrometheusMetricProvider;
use dev\winterframework\io\metrics\prometheus\NoAdapter;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\mcp\exception\McpToolArgumentException;
use dev\winterframework\mcp\invoke\DefaultMcpToolInvoker;
use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\mcp\McpServer;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolRegistry;
use dev\winterframework\mcp\McpValueCodec;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\InternalHttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use ReflectionMethod;
use winterBootTests\Fixtures\Mcp\AdminController;
use winterBootTests\Fixtures\Mcp\Carrier;
use winterBootTests\Fixtures\Mcp\CategoryNode;
use winterBootTests\Fixtures\Mcp\CreateOrder;
use winterBootTests\Fixtures\Mcp\OrderController;
use winterBootTests\Fixtures\Mcp\OrderStatus;
use winterBootTests\Fixtures\Mcp\ShippingService;
use winterBootTests\Fixtures\Mcp\Sub\ImportedDto;
use winterBootTests\Support\TestCase;

final class McpTestProps implements PropertyContext {
    public function __construct(private array $values = []) {
    }

    public function get(string $name, mixed $default = null): mixed {
        return $this->values[$name] ?? $default;
    }

    public function set(string $name, mixed $value): mixed {
        return $this->values[$name] = $value;
    }

    public function getStr(string $name, ?string $default = null): string {
        return (string)$this->get($name, $default);
    }

    public function getBool(string $name, ?bool $default = null): bool {
        return (bool)$this->get($name, $default);
    }

    public function getInt(string $name, ?int $default = null): int {
        return (int)$this->get($name, $default);
    }

    public function getFloat(string $name, ?float $default = null): float {
        return (float)$this->get($name, $default);
    }

    public function getAll(): array {
        return $this->values;
    }

    public function has(string $name): bool {
        return array_key_exists($name, $this->values);
    }
}

final class McpTestCtx extends WinterApplicationContextBuilder {
    public function __construct(public array $byClass = [], public array $byName = []) {
    }

    public function beanByClass(string $class): ?object {
        return $this->byClass[$class] ?? throw new \RuntimeException('no bean ' . $class);
    }

    public function beanByName(string $name): ?object {
        return $this->byName[$name] ?? throw new \RuntimeException('no bean ' . $name);
    }

    public function hasBeanByClass(string $class): bool {
        return isset($this->byClass[$class]);
    }

    public function getId(): string {
        return 'test';
    }

    public function getApplicationName(): string {
        return 'test';
    }

    public function getApplicationVersion(): string {
        return 'test';
    }

    public function getStartupDate(): int {
        return 0;
    }
}

/** Matches with each mapping's own route regex; the real registry keeps static state. */
final class McpTestMappings implements RequestMappingRegistry {
    /** @var RequestMapping[] */
    private array $mappings = [];

    public function put(RequestMapping $mapping) {
        $this->mappings[] = $mapping;
    }

    public function find(string $path, string $method): ?MatchedRequestMapping {
        $path = trim($path, '/');
        foreach ($this->mappings as $m) {
            if (!in_array(strtoupper($method), $m->method, true)) {
                continue;
            }
            foreach ($m->getUriPaths() as $uriPath) {
                if (preg_match('/^' . $uriPath->getRegex() . '$/', $path, $matches)) {
                    return new MatchedRequestMapping($m, $matches);
                }
            }
        }
        return null;
    }

    public function delete(string $path): void {
    }

    public function getAll(): array {
        return $this->mappings;
    }
}

final class McpTestAuthInterceptor implements HandlerInterceptor {
    public array $seen = [];

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        $this->seen[] = $request->getMethod() . ' ' . $request->getUri() . ' auth=' . $request->getFirstHeader('Authorization');
        if ($request->getFirstHeader('Authorization') !== 'Bearer good') {
            $response->withStatus(\dev\winterframework\web\http\HttpStatus::$UNAUTHORIZED);
            return false;
        }
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?\Throwable $ex = null): void {
    }
}

/**
 * Tool invocation: in-process REST requests through the real dispatcher,
 * service argument binding and result conversion.
 */
final class McpInvokerTest extends TestCase {

    private static ?McpToolRegistry $registry = null;
    private static array $resources = [];

    private function registry(): McpToolRegistry {
        if (self::$registry === null) {
            $scanner = ClassResourceScanner::getDefaultScanner();
            $res = ClassResources::ofValues();
            foreach ([OrderController::class, ShippingService::class, AdminController::class] as $cls) {
                $res[] = self::$resources[$cls] = $scanner->scanDefaultClass($cls);
            }
            self::$registry = McpToolRegistry::fromResources($res);
        }
        return self::$registry;
    }

    private function outer(array $headers = []): McpStubRequest {
        return new McpStubRequest($headers + ['Authorization' => 'Bearer good'], '{}');
    }

    // ------------------------------------------------- request building

    public function testRestRequestUsesContextPathEncodedPathAndCallerHeaders(): void {
        $tool = $this->registry()->find('order_get');
        $outer = $this->outer([
            'Origin' => 'https://x', 'MCP-Protocol-Version' => '2025-06-18', 'X-Tenant' => 't1',
        ]);
        $req = DefaultMcpToolInvoker::buildRestRequest($tool, ['id' => 42], $outer, '/svc/');
        $this->assertTrue($req instanceof InternalHttpRequest);
        $this->assertFalse($req->exitsAfterResponse());
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/svc/api/orders/42', $req->getUri());
        $this->assertSame('Bearer good', $req->getFirstHeader('Authorization'));
        $this->assertSame('t1', $req->getFirstHeader('X-Tenant'));
        $this->assertNull($req->getFirstHeader('Origin'));
        $this->assertNull($req->getFirstHeader('MCP-Protocol-Version'));
        $this->assertSame('application/json', $req->getFirstHeader('Accept'));
    }

    public function testRestRequestQueryAndBody(): void {
        $search = $this->registry()->find('search_orders');
        $req = DefaultMcpToolInvoker::buildRestRequest($search, ['status' => 'paid', 'limit' => 5], $this->outer(), '/');
        $this->assertSame('/api/orders/search', $req->getUri());
        $this->assertSame(['status' => 'paid', 'limit' => '5'], $req->getQueryParams());

        $create = $this->registry()->find('create_order');
        $req = DefaultMcpToolInvoker::buildRestRequest($create, ['order' => ['customerId' => 'c/1']], $this->outer(), '');
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/orders', $req->getUri());
        $this->assertSame('{"customerId":"c/1"}', $req->getRawBody());
        $this->assertSame('application/json', $req->getContentType());
    }

    public function testScalarStringsAndTemplates(): void {
        $this->assertSame('true', DefaultMcpToolInvoker::scalarString(true));
        $this->assertSame('2.0', DefaultMcpToolInvoker::scalarString(2.0));
        $this->assertSame('a/b%20c', DefaultMcpToolInvoker::expandTemplate('a/{x}', ['x' => 'b%20c']));
        $this->assertSame('a%2Fb', rawurlencode('a/b'), 'slashes in path values stay in one segment');
    }

    public function testRestResponseMapping(): void {
        $tool = $this->registry()->find('order_get');
        $ok = DefaultMcpToolInvoker::mapRestResponse($tool, 200, 'OK', 'application/json', '{"id":1}');
        $this->assertTrue($ok->isOk());
        $this->assertSame('{"id":1}', $ok->text);

        $this->assertSame(McpInvocationResult::DENIED,
            DefaultMcpToolInvoker::mapRestResponse($tool, 401, 'Unauthorized', '', '')->status);
        $this->assertSame(McpInvocationResult::DENIED,
            DefaultMcpToolInvoker::mapRestResponse($tool, 403, 'Forbidden', '', 'secret')->status);
        $bad = DefaultMcpToolInvoker::mapRestResponse($tool, 400, 'Bad Request', 'application/json', '{"error":"limit"}');
        $this->assertSame(McpInvocationResult::TOOL_ERROR, $bad->status);
        $this->assertSame("HTTP 400 Bad Request\n{\"error\":\"limit\"}", $bad->message);
        $long = DefaultMcpToolInvoker::mapRestResponse($tool, 422, 'Unprocessable', '', str_repeat('x', 10000));
        $this->assertTrue(strlen($long->message) < DefaultMcpToolInvoker::MAX_ERROR_TEXT + 50);
        $err = DefaultMcpToolInvoker::mapRestResponse($tool, 500, 'Internal', 'application/json', '{"trace":"secret"}');
        $this->assertSame(McpInvocationResult::INTERNAL, $err->status);
        $this->assertSame('internal error', $err->message);
        $this->assertSame('ok', DefaultMcpToolInvoker::mapRestResponse($tool, 204, 'No Content', '', '')->text);
    }

    // ----------------------------------------------------- service binding

    public function testServiceArgumentBinding(): void {
        $tool = $this->registry()->find('shipping_track');
        $outer = $this->outer();
        $ctx = new McpToolContext($tool, 3, $outer);
        $args = DefaultMcpToolInvoker::bindServiceArguments(
            $tool,
            ['carrier' => 'Ups', 'numbers' => ['n1'], 'extra' => ['express' => true], 'status' => 'paid'],
            $ctx,
            new ReflectionMethod(ShippingService::class, 'track')
        );
        $this->assertSame(Carrier::Ups, $args[0]);
        $this->assertSame(['n1'], $args[1]);
        $this->assertTrue($args[2] === $outer, 'HttpRequest injected');
        $this->assertTrue($args[3] === $ctx, 'context injected');
        $this->assertTrue($args[4] instanceof ImportedDto && $args[4]->express === true);
        $this->assertSame(OrderStatus::Paid, $args[5]);

        $defaults = DefaultMcpToolInvoker::bindServiceArguments(
            $tool,
            ['carrier' => 'Dhl', 'numbers' => []],
            $ctx,
            new ReflectionMethod(ShippingService::class, 'track')
        );
        $this->assertNull($defaults[4]);
        $this->assertSame(OrderStatus::Open, $defaults[5]);
    }

    public function testBadEnumValueIsArgumentErrorWithoutEcho(): void {
        try {
            McpValueCodec::toPhp('SECRET', (new ReflectionMethod(ShippingService::class, 'track'))->getParameters()[5]->getType(), 'status');
            $this->assertTrue(false, 'expected exception');
        } catch (McpToolArgumentException $e) {
            $this->assertSame('status: not an allowed value', $e->getMessage());
        }
    }

    public function testResultConversionUsesJsonNames(): void {
        $order = new CreateOrder();
        $order->customerId = 'c';
        $order->deliverAfter = new \DateTimeImmutable('2026-10-10T08:00:00+00:00');
        $json = McpValueCodec::toJson($order);
        $this->assertSame('2026-10-10T08:00:00+00:00', $json['deliver_after']);
        $this->assertSame('hidden', $json['internal'] ?? 'hidden');
        $this->assertFalse(array_key_exists('internal', $json), 'private property without JsonProperty');
        $this->assertFalse(array_key_exists('shipTo', $json), 'uninitialized property skipped');
        $this->assertSame('paid', McpValueCodec::toJson(OrderStatus::Paid));
        $this->assertSame('Dhl', McpValueCodec::toJson(Carrier::Dhl));

        $node = new CategoryNode();
        $node->slug = 'loop';
        $node->children = [$node];
        $this->assertThrows(\UnexpectedValueException::class, fn() => McpValueCodec::toJson($node));
    }

    // ------------------------------------------- end to end, real dispatcher

    /** @return array{0: McpServer, 1: McpTestAuthInterceptor, 2: McpTestCtx} */
    private function endToEnd(string $contextPath = '/svc'): array {
        $this->registry();
        $renderer = new DefaultResponseRenderer();
        $error = new DefaultErrorController();
        $prop = new \ReflectionProperty(DefaultErrorController::class, 'renderer');
        $prop->setValue($error, $renderer);

        $appCtx = new McpTestCtx(
            [
                ResponseRenderer::class => $renderer,
                OrderController::class => new OrderController(),
                AdminController::class => new AdminController(),
                ShippingService::class => new ShippingService(),
            ],
            ['errorController' => $error]
        );
        $appCtx->byClass[PrometheusMetricRegistry::class] = new PrometheusMetricRegistry(
            $appCtx,
            '',
            NoAdapter::class,
            DefaultPrometheusMetricProvider::class
        );

        $ctxData = new ApplicationContextData();
        $ctxData->setPropertyContext(new McpTestProps(['server.context-path' => $contextPath]));
        $interceptors = new InterceptorRegistry();
        $auth = new McpTestAuthInterceptor();
        $interceptors->addInterceptor($auth, '^\/svc\/api\/');
        $ctxData->setInterceptorRegistry($interceptors);
        $ctxData->setAopRegistry(new AopInterceptorRegistry($ctxData, $appCtx));

        $mappings = new McpTestMappings();
        foreach ([OrderController::class, AdminController::class] as $cls) {
            foreach (self::$resources[$cls]->getMethods() as $m) {
                foreach ($m->getAttributes() as $attr) {
                    if ($attr instanceof RequestMapping) {
                        $mappings->put($attr);
                    }
                }
            }
        }
        $dispatcher = new DispatcherServlet($mappings, $ctxData, $appCtx);
        $server = new McpServer(
            $this->registry(),
            new DefaultMcpToolInvoker($appCtx, $dispatcher, $contextPath),
            ['name' => 't', 'version' => '1'],
            '',
            $appCtx
        );
        return [$server, $auth, $appCtx];
    }

    private function callTool(McpServer $server, string $name, array $args, array $headers = []): array {
        $r = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]],
            $this->outer($headers)
        );
        return $r['result'];
    }

    public function testRestToolRunsThroughDispatcherWithInterceptors(): void {
        [$server, $auth] = $this->endToEnd();
        OrderController::$calls = [];
        $r = $this->callTool($server, 'order_get', ['id' => 42]);
        $this->assertFalse($r['isError'], json_encode($r));
        $this->assertSame('{"id":42,"status":"open"}', $r['content'][0]['text']);
        $this->assertSame([['method' => 'get', 'args' => [42]]], OrderController::$calls);
        $this->assertSame(['GET /svc/api/orders/42 auth=Bearer good'], $auth->seen, 'route interceptor saw the call');
    }

    public function testRestToolDeniedByRouteInterceptor(): void {
        [$server] = $this->endToEnd();
        OrderController::$calls = [];
        $r = $this->callTool($server, 'order_get', ['id' => 1], ['Authorization' => 'Bearer bad']);
        $this->assertTrue($r['isError']);
        $this->assertSame('not permitted', $r['content'][0]['text']);
        $this->assertSame([], OrderController::$calls);
    }

    public function testRestToolQueryHeaderBodyAndMethodSelection(): void {
        [$server] = $this->endToEnd();
        OrderController::$calls = [];
        $r = $this->callTool($server, 'search_orders', ['status' => 'paid'], ['X-Tenant' => 'acme']);
        $this->assertFalse($r['isError'], json_encode($r));
        $this->assertSame([['method' => 'search', 'args' => ['acme', 'paid', 20]]], OrderController::$calls);
        $this->assertSame('{"items":[{"id":1,"status":"paid"}]}', json_encode($r['structuredContent']));

        OrderController::$calls = [];
        $r = $this->callTool($server, 'create_order', ['order' => [
            'customerId' => 'c1',
            'shipTo' => ['street' => 'Main 1', 'city' => 'Berlin'],
            'lines' => [['sku' => 'MUG', 'quantity' => 2]],
        ]]);
        $this->assertFalse($r['isError'], json_encode($r));
        $order = OrderController::$calls[0]['args'][0];
        $this->assertTrue($order instanceof CreateOrder);
        $this->assertSame('Berlin', $order->shipTo->city);
        $this->assertSame(2, $order->lines[0]->quantity);
        $this->assertSame(7, $r['structuredContent']->id);

        OrderController::$calls = [];
        $r = $this->callTool($server, 'cancel_order', ['id' => 5]);
        $this->assertFalse($r['isError'], json_encode($r));
        $this->assertSame([['method' => 'cancel', 'args' => [5, 'PUT']]], OrderController::$calls);
    }

    public function testRestToolFailureDoesNotLeakAndDoesNotExit(): void {
        [$server] = $this->endToEnd();
        $r = $this->callTool($server, 'order_boom', []);
        // Reaching this line proves the error path did not end the process.
        $this->assertTrue($r['isError']);
        $this->assertSame('internal error', $r['content'][0]['text']);
    }

    public function testOuterRequestStaysBoundAfterInProcessDispatch(): void {
        [$server, , $appCtx] = $this->endToEnd();
        $outer = $this->outer();
        $appCtx->setCurrentHttpRequest($outer);
        $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'order_get', 'arguments' => ['id' => 1]]],
            $outer
        );
        $this->assertTrue($appCtx->getCurrentHttpRequest() === $outer);
        $appCtx->setCurrentHttpRequest(null);
    }

    public function testServiceToolEndToEnd(): void {
        [$server] = $this->endToEnd();
        $r = $this->callTool($server, 'shipping_track', ['carrier' => 'Dhl', 'numbers' => ['A1']]);
        $this->assertFalse($r['isError'], json_encode($r));
        $this->assertSame('{"A1":"Dhl:open:shipping_track"}', json_encode($r['structuredContent']));

        $r = $this->callTool($server, 'shipping_tree', []);
        $this->assertSame('{"slug":"root","children":[]}', json_encode($r['structuredContent']));
    }

    public function testControllerInterceptorBlocksToolCall(): void {
        [$server] = $this->endToEnd();
        AdminController::$statsCalls = 0;

        $r = $this->callTool($server, 'admin_stats', [], ['X-Role' => 'guest']);
        $this->assertTrue($r['isError'], 'refused with 403');
        $this->assertSame('not permitted', $r['content'][0]['text']);
        $this->assertSame(0, AdminController::$statsCalls);

        $r = $this->callTool($server, 'admin_stats', [], ['X-Role' => 'admin']);
        $this->assertFalse($r['isError']);
        $this->assertSame('{"orders":10}', $r['content'][0]['text']);
        $this->assertSame(1, AdminController::$statsCalls);
    }

    public function testVetoWithoutStatusLooksLikeAnEmptySuccess(): void {
        // Same as for an HTTP client: a refusal that sets no status is an empty 200.
        [$server] = $this->endToEnd();
        AdminController::$statsCalls = 0;
        $r = $this->callTool($server, 'admin_stats', [], ['X-Silent-Veto' => '1']);
        $this->assertFalse($r['isError']);
        $this->assertSame('ok', $r['content'][0]['text']);
        $this->assertSame(0, AdminController::$statsCalls, 'the method still did not run');
    }
}
