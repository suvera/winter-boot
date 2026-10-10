<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/fixtures/mcp/Sub/ImportedDto.php';
require_once __DIR__ . '/fixtures/mcp/McpFixtures.php';
require_once __DIR__ . '/fixtures/mcp/McpCatalogFixtures.php';
require_once __DIR__ . '/McpInvokerTest.php';

use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\invoke\McpInvocationResult;
use dev\winterframework\mcp\McpPromptDefinition;
use dev\winterframework\mcp\McpResourceDefinition;
use dev\winterframework\mcp\McpServer;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolDefinition;
use dev\winterframework\mcp\McpToolInterceptor;
use dev\winterframework\mcp\McpToolRegistry;
use dev\winterframework\mcp\McpUriTemplate;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\mcp\McpPrompt;
use dev\winterframework\stereotype\mcp\McpResource;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\web\http\HttpRequest;
use ReflectionMethod;
use Throwable;
use winterBootTests\Fixtures\Mcp\ShippingService;
use winterBootTests\Fixtures\Mcp\SiteCatalog;
use winterBootTests\Support\TestCase;

#[Service]
class McpRpBadService {
    public function missingParam(string $domain): string {
        return '';
    }

    public function extraParam(string $domain, string $other): string {
        return '';
    }

    public function intPrompt(int $n): string {
        return '';
    }

    public function listNeedsArg(string $x): array {
        return [];
    }

    public function plain(): string {
        return '';
    }
}

#[RestController]
class McpRpController {
    public function res(): string {
        return '';
    }
}

final class McpRpArgsInterceptor implements McpToolInterceptor {
    public array $seen = [];

    public function isVisible(McpToolDefinition $tool, HttpRequest $request): bool {
        return true;
    }

    public function beforeCall(McpToolDefinition $tool, McpToolContext $ctx): void {
        $this->seen[] = $ctx->getArguments();
    }

    public function afterCall(McpToolDefinition $tool, McpToolContext $ctx, ?Throwable $error): void {
        $this->seen[] = 'site=' . $ctx->getArgument('carrier', '-');
    }
}

final class McpRpOutcomeInterceptor implements McpToolInterceptor {
    public array $outcomes = [];
    public bool $deny = false;

    public function isVisible(McpToolDefinition $tool, HttpRequest $request): bool {
        return true;
    }

    public function beforeCall(McpToolDefinition $tool, McpToolContext $ctx): void {
        if ($this->deny) {
            throw new McpToolDeniedException('test');
        }
    }

    public function afterCall(McpToolDefinition $tool, McpToolContext $ctx, ?Throwable $error): void {
        $this->outcomes[] = [$ctx->getOutcome(), $ctx->isOk()];
    }
}

/**
 * MCP resources and prompts (#[McpResource], #[McpPrompt]), tool call
 * arguments on McpToolContext, and capabilities.
 */
final class McpResourcePromptTest extends TestCase {

    private function registry(): McpToolRegistry {
        $scanner = ClassResourceScanner::getDefaultScanner();
        $res = ClassResources::ofValues();
        $res[] = $scanner->scanDefaultClass(SiteCatalog::class);
        $res[] = $scanner->scanDefaultClass(ShippingService::class);
        return McpToolRegistry::fromResources($res);
    }

    private function server(?McpToolRegistry $registry = null): McpServer {
        $ctx = new McpTestCtx([SiteCatalog::class => new SiteCatalog(), ShippingService::class => new ShippingService()]);
        return new McpServer(
            $registry ?? $this->registry(),
            new McpFakeInvoker(),
            ['name' => 'snow', 'version' => '1', 'title' => 'Snow Analytics'],
            '',
            $ctx
        );
    }

    private function rpc(McpServer $s, string $method, array $params = [], array $headers = []): array {
        return $s->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            new McpStubRequest($headers));
    }

    // ------------------------------------------------------------ templates

    public function testUriTemplateMatching(): void {
        $t = new McpUriTemplate('site://{domain}/summary/{range}');
        $this->assertSame(['domain', 'range'], $t->getVariables());
        $this->assertSame(['domain' => 'a b.com', 'range' => 'today'], $t->match('site://a%20b.com/summary/today'));
        $this->assertNull($t->match('site://a.com/summary/today/x'), 'a placeholder never spans "/"');
        $this->assertNull($t->match('site://a.com/other/today'));
        $this->assertFalse((new McpUriTemplate('config://app'))->isTemplate());
        $this->assertNull((new McpUriTemplate('a.b://{x}'))->match('a-b://1'), 'literal dots are escaped');
    }

    // ---------------------------------------------------------- definitions

    public function testDiscoveryAndListEntries(): void {
        $r = $this->registry();
        $this->assertSame(['app_config', 'site_summary', 'site_catalog_item'], array_keys($r->resources()));
        $this->assertSame(['weekly_report', 'site_catalog_explain_spike', 'site_catalog_broken'], array_keys($r->prompts()));

        $this->assertSame(
            ['uri' => 'config://app', 'name' => 'app_config', 'title' => 'App configuration',
                'description' => 'Public settings of this server.', 'mimeType' => 'application/json'],
            $r->resources()['app_config']->toListEntry()
        );
        $this->assertSame(
            ['uriTemplate' => 'site://{domain}/summary/{range}', 'name' => 'site_summary',
                'title' => 'Site traffic summary',
                'description' => 'Traffic overview for a site; range is "today" or "last-7-days".',
                'mimeType' => 'application/json'],
            $r->resources()['site_summary']->toListEntry()
        );
        $this->assertSame('text/plain', $r->resources()['site_catalog_item']->mimeType, 'string return');

        $this->assertSame(
            ['name' => 'weekly_report', 'title' => 'Weekly report',
                'description' => 'Summarise the last 7 days against the week before.',
                'arguments' => [
                    ['name' => 'site', 'description' => 'Site domain.', 'required' => true],
                    ['name' => 'period', 'description' => 'Period, e.g. 30d.', 'required' => false],
                ]],
            $r->prompts()['weekly_report']->toListEntry()
        );
    }

    public function testBootErrors(): void {
        $res = fn(string $m, string $uri, ?string $list = null) => McpResourceDefinition::create(
            new ReflectionMethod(McpRpBadService::class, $m), new McpResource(uri: $uri, listMethod: $list));
        $cases = [
            'placeholder without parameter' => fn() => $res('plain', 'x://{id}'),
            'parameter that is not a placeholder' => fn() => $res('extraParam', 'x://{domain}'),
            'placeholder named twice' => fn() => $res('missingParam', 'x://{domain}/{domain}'),
            'listMethod missing' => fn() => $res('plain', 'x://a', 'nope'),
            'listMethod with arguments' => fn() => $res('plain', 'x://a', 'listNeedsArg'),
            'non-string prompt argument' => fn() => McpPromptDefinition::create(
                new ReflectionMethod(McpRpBadService::class, 'intPrompt'), new McpPrompt(description: 'd')),
            'resource on a controller' => fn() => (new McpResource(uri: 'x://a'))->init(
                RefMethod::getInstance(new ReflectionMethod(McpRpController::class, 'res'))),
            'relative uri' => fn() => (new McpResource(uri: 'no-scheme'))->init(
                RefMethod::getInstance(new ReflectionMethod(McpRpBadService::class, 'plain'))),
        ];
        foreach ($cases as $label => $fn) {
            $this->assertThrows(McpDefinitionException::class, $fn, $label);
        }

        $registry = new McpToolRegistry();
        $registry->addResource($res('plain', 'x://a'));
        $this->assertThrows(McpDefinitionException::class, fn() => $registry->addResource(
            McpResourceDefinition::create(new ReflectionMethod(McpRpBadService::class, 'plain'),
                new McpResource(uri: 'x://a', name: 'other'))), 'duplicate URI');
        $registry->addPrompt(McpPromptDefinition::create(new ReflectionMethod(McpRpBadService::class, 'plain'),
            new McpPrompt(description: 'd', name: 'p')));
        $this->assertThrows(McpDefinitionException::class, fn() => $registry->addPrompt(
            McpPromptDefinition::create(new ReflectionMethod(McpRpBadService::class, 'plain'),
                new McpPrompt(description: 'd', name: 'p'))), 'duplicate prompt name');
    }

    // ------------------------------------------------------------ protocol

    public function testCapabilitiesAndServerTitle(): void {
        $r = $this->rpc($this->server(), 'initialize', ['protocolVersion' => '2025-06-18']);
        $this->assertSame(
            ['tools' => ['listChanged' => false], 'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false]],
            $r['result']['capabilities']
        );
        $this->assertSame('Snow Analytics', $r['result']['serverInfo']['title']);
    }

    public function testResourcesListIncludesCallerSpecificEntries(): void {
        $all = $this->rpc($this->server(), 'resources/list')['result']['resources'];
        $this->assertSame(['config://app', 'site://example.com/summary/today', 'site://shop.example/summary/today'],
            array_column($all, 'uri'));
        $this->assertSame('application/json', $all[1]['mimeType'], 'template mimeType as default');

        $limited = $this->rpc($this->server(), 'resources/list', [], ['X-Key' => 'limited'])['result']['resources'];
        $this->assertSame(['config://app', 'site://example.com/summary/today'], array_column($limited, 'uri'));

        $templates = $this->rpc($this->server(), 'resources/templates/list')['result']['resourceTemplates'];
        $this->assertSame(['site://{domain}/summary/{range}', 'item://{id}'], array_column($templates, 'uriTemplate'));
    }

    public function testReadResources(): void {
        $s = $this->server();
        $r = $this->rpc($s, 'resources/read', ['uri' => 'config://app'])['result']['contents'][0];
        $this->assertSame('config://app', $r['uri']);
        $this->assertSame('{"version":"1.0","empty":{}}', $r['text']);

        $r = $this->rpc($s, 'resources/read', ['uri' => 'site://example.com/summary/today'], ['X-Key' => 'k1']);
        $this->assertSame('{"site":"example.com","range":"today","visitors":42,"key":"k1"}',
            $r['result']['contents'][0]['text'], 'placeholders and HttpRequest bound');

        $r = $this->rpc($s, 'resources/read', ['uri' => 'item://7'])['result']['contents'][0];
        $this->assertSame(['uri' => 'item://7', 'mimeType' => 'text/plain', 'text' => 'item #7'], $r);
    }

    public function testResourceNotFoundCases(): void {
        $s = $this->server();
        foreach ([
            'unknown://x' => 'no resource matches',
            'site://nope.com/summary/today' => 'method throws McpResourceNotFoundException',
            'site://example.com/summary/forever' => 'method rejects the range',
            'site://secret.example/summary/today' => 'a 403 never confirms the resource exists',
            'item://abc' => 'int placeholder that is not a number',
            'item://404' => 'method returns null',
        ] as $uri => $label) {
            $r = $this->rpc($s, 'resources/read', ['uri' => $uri]);
            $this->assertSame(McpServer::RESOURCE_NOT_FOUND, $r['error']['code'] ?? null, $label);
            $this->assertSame(['uri' => $uri], $r['error']['data'], $label);
        }
        $this->assertSame(McpServer::INVALID_PARAMS, $this->rpc($s, 'resources/read', [])['error']['code']);
    }

    public function testPrompts(): void {
        $s = $this->server();
        $list = $this->rpc($s, 'prompts/list')['result']['prompts'];
        $this->assertSame(['weekly_report', 'site_catalog_explain_spike', 'site_catalog_broken'], array_column($list, 'name'));

        $r = $this->rpc($s, 'prompts/get', ['name' => 'weekly_report', 'arguments' => ['site' => 'a.com']])['result'];
        $this->assertSame('Summarise the last 7 days against the week before.', $r['description']);
        $this->assertSame([['role' => 'user', 'content' => ['type' => 'text',
            'text' => 'Write a weekly report for a.com over the last 7 days.']]], $r['messages']);

        $r = $this->rpc($s, 'prompts/get', ['name' => 'site_catalog_explain_spike',
            'arguments' => ['site' => 'a.com', 'date' => '2026-10-01']])['result'];
        $this->assertSame(['user', 'assistant'], array_column($r['messages'], 'role'));
        $this->assertSame('Traffic for a.com changed around 2026-10-01.', $r['messages'][0]['content']['text']);

        foreach ([
            [['name' => 'weekly_report'], 'argument "site" is required'],
            [['name' => 'weekly_report', 'arguments' => ['site' => 'a', 'evil' => 'x']], 'unknown argument "evil"'],
            [['name' => 'weekly_report', 'arguments' => ['site' => 5]], 'argument "site" must be a string'],
            [['name' => 'nope'], 'unknown prompt: nope'],
        ] as [$params, $message]) {
            $err = $this->rpc($s, 'prompts/get', $params)['error'];
            $this->assertSame(McpServer::INVALID_PARAMS, $err['code'], $message);
            $this->assertSame($message, $err['message']);
        }
        $this->assertSame(McpServer::INTERNAL_ERROR,
            $this->rpc($s, 'prompts/get', ['name' => 'site_catalog_broken'])['error']['code'], 'bad return value');
    }

    public function testMethodsAbsentWithoutResourcesOrPrompts(): void {
        $registry = new McpToolRegistry();
        $s = new McpServer($registry, new McpFakeInvoker(), ['name' => 'x', 'version' => '1']);
        foreach (['resources/list', 'resources/templates/list', 'resources/read', 'prompts/list', 'prompts/get'] as $m) {
            $this->assertSame(McpServer::METHOD_NOT_FOUND, $this->rpc($s, $m)['error']['code'], $m);
        }
    }

    public function testToolContextCarriesArguments(): void {
        $registry = $this->registry();
        $interceptor = new McpRpArgsInterceptor();
        $registry->setInterceptors([$interceptor]);
        $this->rpc($this->server($registry), 'tools/call',
            ['name' => 'shipping_track', 'arguments' => ['carrier' => 'Dhl', 'numbers' => ['A']]]);
        $this->assertSame([['carrier' => 'Dhl', 'numbers' => ['A']], 'site=Dhl'], $interceptor->seen);
    }

    public function testAfterCallSeesTheOutcome(): void {
        $call = function (McpInvocationResult $result, array $args, bool $deny = false): array {
            $registry = $this->registry();
            $interceptor = new McpRpOutcomeInterceptor();
            $interceptor->deny = $deny;
            $registry->setInterceptors([$interceptor]);
            $server = new McpServer($registry, new McpFakeInvoker($result), ['name' => 'x', 'version' => '1']);
            $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'shipping_count', 'arguments' => $args]], new McpStubRequest());
            return $interceptor->outcomes;
        };
        $this->assertSame([['ok', true]], $call(McpInvocationResult::ok(1), ['a' => 1]));
        $this->assertSame([['tool_error', false]], $call(McpInvocationResult::ok(1), ['a' => 'x']), 'invalid arguments');
        $this->assertSame([['tool_error', false]], $call(McpInvocationResult::toolError('limit'), ['a' => 1]));
        $this->assertSame([['denied', false]], $call(McpInvocationResult::denied(), ['a' => 1]));
        $this->assertSame([['denied', false]], $call(McpInvocationResult::ok(1), ['a' => 1], true), 'interceptor veto');
        $this->assertSame([['internal', false]], $call(McpInvocationResult::internal(), ['a' => 1]));
        $this->assertSame([['internal', false]], $call(McpInvocationResult::ok('not an int'), ['a' => 1]),
            'result breaking its output schema');
    }
}
