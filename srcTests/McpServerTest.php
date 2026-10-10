<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/fixtures/mcp/Sub/ImportedDto.php';
require_once __DIR__ . '/fixtures/mcp/McpFixtures.php';

use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\mcp\invoke\McpToolInvoker;
use dev\winterframework\mcp\McpController;
use dev\winterframework\mcp\McpServer;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolDefinition;
use dev\winterframework\mcp\McpToolInterceptor;
use dev\winterframework\mcp\McpToolRegistry;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;
use winterBootTests\Fixtures\Mcp\OrderController;
use winterBootTests\Fixtures\Mcp\ShippingService;
use winterBootTests\Support\TestCase;

final class McpFakeInvoker implements McpToolInvoker {
    public array $calls = [];

    public function __construct(private ?McpInvocationResult $next = null) {
    }

    public function invoke(McpToolDefinition $tool, array $arguments, McpToolContext $ctx): McpInvocationResult {
        $this->calls[] = [$tool->name, $arguments];
        return $this->next ?? McpInvocationResult::ok(['ok' => true]);
    }
}

final class McpRecordingInterceptor implements McpToolInterceptor {
    public array $events = [];

    public function __construct(private array $hidden = [], private array $denied = []) {
    }

    public function isVisible(McpToolDefinition $tool, HttpRequest $request): bool {
        return !in_array($tool->name, $this->hidden, true);
    }

    public function beforeCall(McpToolDefinition $tool, McpToolContext $ctx): void {
        $this->events[] = 'before:' . $tool->name;
        if (in_array($tool->name, $this->denied, true)) {
            throw new McpToolDeniedException('key is read-only');
        }
    }

    public function afterCall(McpToolDefinition $tool, McpToolContext $ctx, ?Throwable $error): void {
        $this->events[] = 'after:' . $tool->name . ($error ? ':error' : '');
    }
}

final class McpStubRequest extends HttpRequest {
    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(array $headers = [], string $body = '', string $contentType = 'application/json') {
        $this->headers = new HttpHeaders();
        foreach ($headers as $k => $v) {
            $this->headers->add($k, $v);
        }
        $this->body = $body;
        $this->contentType = $contentType;
        $this->method = 'POST';
        $this->uri = '/mcp';
        $this->queryParams = [];
        $this->postParams = [];
        $this->cookies = [];
        $this->files = [];
    }

    public function exitsAfterResponse(): bool {
        return false;
    }
}

/**
 * MCP protocol handling (JSON-RPC, tools/list, tools/call shaping) and
 * the endpoint's transport checks.
 */
final class McpServerTest extends TestCase {

    private function registry(): McpToolRegistry {
        $scanner = ClassResourceScanner::getDefaultScanner();
        $res = ClassResources::ofValues();
        $res[] = $scanner->scanDefaultClass(OrderController::class);
        $res[] = $scanner->scanDefaultClass(ShippingService::class);
        return McpToolRegistry::fromResources($res);
    }

    private function server(?McpToolInvoker $invoker = null, ?McpToolRegistry $registry = null): McpServer {
        return new McpServer(
            $registry ?? $this->registry(),
            $invoker ?? new McpFakeInvoker(),
            ['name' => 'test-app', 'version' => '1.2.3'],
            'Use search_orders first.'
        );
    }

    private function call(McpServer $server, string $method, array $params = [], int|string $id = 1): ?array {
        return $server->handle(
            ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params],
            new McpStubRequest()
        );
    }

    // ----------------------------------------------------------- protocol

    public function testInitializeNegotiatesVersion(): void {
        $r = $this->call($this->server(), 'initialize', ['protocolVersion' => '2025-06-18']);
        $this->assertSame('2025-06-18', $r['result']['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => false]], $r['result']['capabilities']);
        $this->assertSame(['name' => 'test-app', 'version' => '1.2.3'], $r['result']['serverInfo']);
        $this->assertSame('Use search_orders first.', $r['result']['instructions']);

        $r = $this->call($this->server(), 'initialize', ['protocolVersion' => '1999-01-01']);
        $this->assertSame(McpServer::LATEST_VERSION, $r['result']['protocolVersion']);
    }

    public function testNotificationsAndClientResponsesGetNoAnswer(): void {
        $req = new McpStubRequest();
        $this->assertNull($this->server()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $req));
        $this->assertNull($this->server()->handle(['jsonrpc' => '2.0', 'id' => 5, 'result' => []], $req));
    }

    public function testInvalidMessages(): void {
        $req = new McpStubRequest();
        $s = $this->server();
        $this->assertSame(McpServer::INVALID_REQUEST, $s->handle('x', $req)['error']['code']);
        $this->assertSame(McpServer::INVALID_REQUEST, $s->handle(['id' => 1, 'method' => 'ping'], $req)['error']['code']);
        $this->assertSame(McpServer::INVALID_REQUEST,
            $s->handle(['jsonrpc' => '2.0', 'id' => [1], 'method' => 'ping'], $req)['error']['code']);
        $this->assertSame(McpServer::METHOD_NOT_FOUND, $this->call($s, 'resources/list')['error']['code']);
        $this->assertSame(McpServer::INVALID_PARAMS,
            $s->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => [1]], $req)['error']['code']);
        $ping = $this->call($s, 'ping', [], 'abc');
        $this->assertSame('abc', $ping['id']);
        $this->assertSame('{}', json_encode($ping['result']));
    }

    public function testToolsListHonoursInterceptorVisibility(): void {
        $registry = $this->registry();
        $registry->setInterceptors([new McpRecordingInterceptor(hidden: ['order_boom'])]);
        $r = $this->call($this->server(null, $registry), 'tools/list');
        $names = array_column($r['result']['tools'], 'name');
        $this->assertFalse(in_array('order_boom', $names, true));
        $this->assertTrue(in_array('order_get', $names, true));

        // A hidden tool answers like an unknown one.
        $err = $this->call($this->server(null, $registry), 'tools/call', ['name' => 'order_boom']);
        $this->assertSame(McpServer::INVALID_PARAMS, $err['error']['code']);
        $this->assertSame('unknown tool: order_boom', $err['error']['message']);
    }

    public function testUnknownToolNameIsNotEchoedWhenMalformed(): void {
        $err = $this->call($this->server(), 'tools/call', ['name' => "<script>\n"]);
        $this->assertSame('unknown tool: (invalid name)', $err['error']['message']);
    }

    public function testInvalidArgumentsAreToolErrorsAndSkipTheInvoker(): void {
        $invoker = new McpFakeInvoker();
        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'order_get', 'arguments' => ['id' => 'x']]);
        $this->assertTrue($r['result']['isError']);
        $this->assertSame('id: expected integer', $r['result']['content'][0]['text']);
        $this->assertSame([], $invoker->calls);

        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'order_get', 'arguments' => [1]]);
        $this->assertSame(McpServer::INVALID_PARAMS, $r['error']['code']);
    }

    public function testInterceptorCanDenyAndAlwaysSeesAfterCall(): void {
        $registry = $this->registry();
        $interceptor = new McpRecordingInterceptor(denied: ['order_get']);
        $registry->setInterceptors([$interceptor]);
        $invoker = new McpFakeInvoker();
        $r = $this->call($this->server($invoker, $registry), 'tools/call', ['name' => 'order_get', 'arguments' => ['id' => 1]]);
        $this->assertTrue($r['result']['isError']);
        $this->assertSame('not permitted', $r['result']['content'][0]['text']);
        $this->assertSame([], $invoker->calls);
        $this->assertSame(['before:order_get', 'after:order_get:error'], $interceptor->events);
    }

    public function testResultShapes(): void {
        // list result wrapped as {"items": [...]}, checked against the output schema
        $invoker = new McpFakeInvoker(McpInvocationResult::ok([['id' => 1, 'status' => 'open']]));
        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'search_orders', 'arguments' => []]);
        $this->assertFalse($r['result']['isError']);
        $this->assertSame('{"items":[{"id":1,"status":"open"}]}', json_encode($r['result']['structuredContent']));
        $this->assertSame('[{"id":1,"status":"open"}]', $r['result']['content'][0]['text']);

        // scalar wrapped as {"result": ...}
        $invoker = new McpFakeInvoker(McpInvocationResult::ok(4));
        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'shipping_count', 'arguments' => ['a' => 4]]);
        $this->assertSame('{"result":4}', json_encode($r['result']['structuredContent']));

        // no output schema: text only
        $invoker = new McpFakeInvoker(McpInvocationResult::ok(null));
        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'shipping_noop']);
        $this->assertSame('ok', $r['result']['content'][0]['text']);
        $this->assertFalse(isset($r['result']['structuredContent']));

        // empty JSON objects stay objects
        $invoker = new McpFakeInvoker(McpInvocationResult::ok(new \stdClass()));
        $r = $this->call($this->server($invoker), 'tools/call', [
            'name' => 'shipping_track', 'arguments' => ['carrier' => 'Dhl', 'numbers' => []],
        ]);
        $this->assertSame('{}', json_encode($r['result']['structuredContent']));
    }

    public function testResultBreakingOutputSchemaIsInternalError(): void {
        $invoker = new McpFakeInvoker(McpInvocationResult::ok([['id' => 'not-an-int', 'status' => 'open']]));
        $r = $this->call($this->server($invoker), 'tools/call', ['name' => 'search_orders', 'arguments' => []]);
        $this->assertTrue($r['result']['isError']);
        $this->assertSame('internal error', $r['result']['content'][0]['text']);
    }

    public function testInvokerOutcomesMapToMessages(): void {
        foreach ([
            [McpInvocationResult::toolError('limit: too big'), 'limit: too big'],
            [McpInvocationResult::denied(), 'not permitted'],
            [McpInvocationResult::internal(), 'internal error'],
        ] as [$outcome, $text]) {
            $r = $this->call($this->server(new McpFakeInvoker($outcome)), 'tools/call', ['name' => 'order_boom']);
            $this->assertTrue($r['result']['isError']);
            $this->assertSame($text, $r['result']['content'][0]['text']);
        }
    }

    // ---------------------------------------------------------- transport

    private function status(?ResponseEntity $r): ?int {
        return $r?->getStatus()->getValue();
    }

    public function testTransportChecks(): void {
        $ok = new McpStubRequest(['Accept' => 'application/json, text/event-stream'], '{}');
        $this->assertNull(McpController::checkTransport($ok, [], 100));

        $this->assertSame(403, $this->status(McpController::checkTransport(
            new McpStubRequest(['Origin' => 'http://evil.example'], '{}'), [], 100)), 'unknown origin');
        $this->assertNull(McpController::checkTransport(
            new McpStubRequest(['Origin' => 'https://app.example/'], '{}'), ['https://app.example'], 100), 'allowed origin');
        $this->assertSame(400, $this->status(McpController::checkTransport(
            new McpStubRequest(['MCP-Protocol-Version' => '2020-01-01'], '{}'), [], 100)), 'bad version header');
        $this->assertSame(415, $this->status(McpController::checkTransport(
            new McpStubRequest([], '{}', 'text/plain'), [], 100)), 'content type');
        $this->assertNull(McpController::checkTransport(
            new McpStubRequest([], '{}', 'application/json; charset=utf-8'), [], 100), 'content type parameters');
        $this->assertSame(406, $this->status(McpController::checkTransport(
            new McpStubRequest(['Accept' => 'text/html'], '{}'), [], 100)), 'accept');
        $this->assertSame(413, $this->status(McpController::checkTransport(
            new McpStubRequest([], str_repeat(' ', 101) . '{}'), [], 100)), 'size cap');
    }

    public function testEndpointParseErrorsBatchesAndNotifications(): void {
        $controller = new McpController($this->server());
        $r = $controller->post(new McpStubRequest([], '{nope'));
        $this->assertSame(400, $this->status($r));
        $this->assertSame(McpServer::PARSE_ERROR, json_decode($r->getBody(), true)['error']['code']);

        $r = $controller->post(new McpStubRequest([], '[{"jsonrpc":"2.0","id":1,"method":"ping"}]'));
        $this->assertSame(400, $this->status($r));
        $this->assertSame('batch requests are not supported', json_decode($r->getBody(), true)['error']['message']);

        $r = $controller->post(new McpStubRequest([], '{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        $this->assertSame(202, $this->status($r));

        $r = $controller->post(new McpStubRequest([], '{"jsonrpc":"2.0","id":9,"method":"tools/list"}'));
        $this->assertSame(200, $this->status($r));
        $body = json_decode($r->getBody(), true);
        $this->assertSame(9, $body['id']);
        $this->assertTrue(count($body['result']['tools']) > 0);
        // empty "properties" stay JSON objects on the wire
        $this->assertTrue(str_contains($r->getBody(), '"properties":{}'));

        $this->assertSame(405, $this->status($controller->notAllowed()));
        $this->assertSame('POST', $controller->notAllowed()->getHeaders()->getFirst('Allow'));
    }
}
