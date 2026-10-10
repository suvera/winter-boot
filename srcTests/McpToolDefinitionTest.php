<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/fixtures/mcp/Sub/ImportedDto.php';
require_once __DIR__ . '/fixtures/mcp/McpFixtures.php';

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\McpToolDefinition;
use dev\winterframework\mcp\McpToolDefinitionFactory;
use dev\winterframework\mcp\McpToolRegistry;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use ReflectionMethod;
use winterBootTests\Fixtures\Mcp\NoStereotype;
use winterBootTests\Fixtures\Mcp\OrderController;
use winterBootTests\Fixtures\Mcp\ShippingService;
use winterBootTests\Support\TestCase;

#[RestController]
class McpDefMultiController {
    #[RequestMapping(path: '/multi/{id}', method: [RequestMethod::GET, RequestMethod::POST])]
    public function multi(#[\dev\winterframework\stereotype\web\PathVariable] int $id): array {
        return [];
    }

    public function unmapped(): array {
        return [];
    }

    #[GetMapping(path: '/typed')]
    public function typed(): McpDefView {
        return new McpDefView();
    }
}

class McpDefView {
    public int $id = 0;
}

#[Service]
class McpDefService {
    public function untypedArray(array $filters): void {
    }

    /** @param array<string, string> $filters */
    public function docArray(array $filters): void {
    }

    public function scalar(int $a, ?string $b, string $c = 'x'): string {
        return '';
    }

    public function returnsView(): McpDefView {
        return new McpDefView();
    }

    /** No description attribute, but a summary. */
    public function summaryOnly(): void {
    }

    public function noDoc(): void {
    }

    public static function staticTool(): void {
    }
}

/**
 * #[McpTool] derivation: names, hints, bindings, schemas, and the boot
 * errors for declarations that can't be served.
 */
final class McpToolDefinitionTest extends TestCase {

    private static ?McpToolRegistry $fixtures = null;

    private function fixtures(): McpToolRegistry {
        if (self::$fixtures === null) {
            $scanner = ClassResourceScanner::getDefaultScanner();
            $res = ClassResources::ofValues();
            $res[] = $scanner->scanDefaultClass(OrderController::class);
            $res[] = $scanner->scanDefaultClass(ShippingService::class);
            self::$fixtures = McpToolRegistry::fromResources($res);
        }
        return self::$fixtures;
    }

    private function def(string $class, string $method, McpTool $tool, ?RequestMapping $mapping = null): McpToolDefinition {
        if ($mapping !== null) {
            $mapping->init(RefMethod::getInstance(new ReflectionMethod($class, $method)));
        }
        return McpToolDefinitionFactory::create(new ReflectionMethod($class, $method), $tool, $mapping);
    }

    private function mappingOf(string $class, string $method): ?RequestMapping {
        $ref = new ReflectionMethod($class, $method);
        foreach ($ref->getAttributes() as $a) {
            $obj = $a->newInstance();
            if ($obj instanceof RequestMapping) {
                $obj->init(RefMethod::getInstance($ref));
                return $obj;
            }
        }
        return null;
    }

    public function testNameDerivation(): void {
        $this->assertSame('order_get', McpToolDefinitionFactory::deriveName('OrderController', 'get'));
        $this->assertSame('invoice_resend_email', McpToolDefinitionFactory::deriveName('InvoiceService', 'resendEmail'));
        $this->assertSame('http_client_get_url', McpToolDefinitionFactory::deriveName('HTTPClient', 'getURL'));
        $this->assertSame('controller_x', McpToolDefinitionFactory::deriveName('Controller', 'x'));
    }

    public function testFixtureToolsAreDiscovered(): void {
        $names = array_keys($this->fixtures()->all());
        sort($names);
        $this->assertSame([
            'cancel_order', 'create_order', 'order_boom', 'order_get', 'search_orders',
            'shipping_count', 'shipping_noop', 'shipping_track', 'shipping_tree',
        ], $names);
    }

    public function testRestBindingsHideHeadersAndFollowRequestParamSemantics(): void {
        $t = $this->fixtures()->find('search_orders');
        $this->assertSame('GET', $t->httpMethod);
        $this->assertSame('api/orders/search', $t->routeTemplate);
        $this->assertSame(['status', 'limit'], array_keys($t->inputSchema['properties']));
        $this->assertFalse(isset($t->inputSchema['required']), 'required: false params are optional');
        $this->assertSame(20, $t->inputSchema['properties']['limit']['default']);
        $this->assertSame(McpToolDefinition::WRAP_ITEMS, $t->outputWrap);
        $this->assertSame(['title' => 'Search orders', 'readOnlyHint' => true], $t->annotations);
    }

    public function testPathVariableIsRequiredNonNegativeInteger(): void {
        $t = $this->fixtures()->find('order_get');
        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'description' => 'Order number.'],
            $t->inputSchema['properties']['id']);
        $this->assertSame(['id'], $t->inputSchema['required']);
        $this->assertSame('Fetch one order.', $t->description);
        $this->assertNull($t->outputSchema, 'untyped array return has no output schema');
    }

    public function testBodyIsNestedAndOutputTypeDescribesResponseEntity(): void {
        $t = $this->fixtures()->find('create_order');
        $this->assertSame(['$ref' => '#/$defs/CreateOrder'], $t->inputSchema['properties']['order']);
        $this->assertTrue(isset($t->inputSchema['$defs']['Address']));
        $this->assertSame(McpToolDefinition::WRAP_OBJECT, $t->outputWrap);
        $this->assertSame(['id', 'status', 'createdAt'], $t->outputSchema['required']);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            $t->annotations);
    }

    public function testHttpMethodSelectsAmongMappingMethods(): void {
        $t = $this->fixtures()->find('cancel_order');
        $this->assertSame('PUT', $t->httpMethod);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            $t->annotations);
        $this->assertSame(['id'], array_keys($t->inputSchema['properties']), 'HttpRequest is injected, not input');
    }

    public function testServiceToolDerivation(): void {
        $t = $this->fixtures()->find('shipping_track');
        $this->assertSame(McpToolDefinition::KIND_SERVICE, $t->kind);
        $this->assertSame(['carrier', 'numbers', 'extra', 'status'], array_keys($t->inputSchema['properties']));
        $this->assertSame(['carrier', 'numbers'], $t->inputSchema['required']);
        $this->assertSame('open', $t->inputSchema['properties']['status']['default']);
        $this->assertSame(['type' => 'string'], $t->outputSchema['additionalProperties']);
        $this->assertSame(['title' => 'Track parcels', 'readOnlyHint' => true, 'openWorldHint' => true],
            $t->annotations);
        $binds = array_column($t->bindings, 'bind', 'param');
        $this->assertSame(McpToolDefinition::BIND_HTTP_REQUEST, $binds['request']);
        $this->assertSame(McpToolDefinition::BIND_CONTEXT, $binds['ctx']);

        $count = $this->fixtures()->find('shipping_count');
        $this->assertSame(McpToolDefinition::WRAP_RESULT, $count->outputWrap);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
            $count->annotations, 'service tools are assumed to write');

        $this->assertSame(McpToolDefinition::WRAP_NONE, $this->fixtures()->find('shipping_noop')->outputWrap);
        $tree = $this->fixtures()->find('shipping_tree');
        $this->assertSame(['$ref' => '#'], $tree->outputSchema['properties']['children']['items']);
    }

    public function testServiceNullableWithoutDefaultIsOptional(): void {
        $t = $this->def(McpDefService::class, 'scalar', new McpTool(description: 'd'));
        $this->assertSame(['a'], $t->inputSchema['required']);
        $this->assertSame(['string', 'null'], $t->inputSchema['properties']['b']['type']);
        $this->assertSame('x', $t->inputSchema['properties']['c']['default']);
    }

    public function testHints(): void {
        foreach ([
            ['GET', [], ['readOnlyHint' => true]],
            ['PUT', [], ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]],
            ['DELETE', [], ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true]],
            ['POST', [], ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false]],
            ['POST', ['readOnly' => true], ['readOnlyHint' => true]],
            ['GET', ['readOnly' => false], ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false]],
            ['GET', ['openWorld' => false], ['readOnlyHint' => true, 'openWorldHint' => false]],
        ] as [$method, $args, $expected]) {
            [, $ann] = McpToolDefinitionFactory::hints(new McpTool(...$args), $method);
            $this->assertSame($expected, $ann, $method . ' ' . json_encode($args));
        }
    }

    public function testDescriptionFromSummaryOrBootError(): void {
        $t = $this->def(McpDefService::class, 'summaryOnly', new McpTool());
        $this->assertSame('No description attribute, but a summary.', $t->description);
        $this->assertThrows(McpDefinitionException::class, function () {
            $this->def(McpDefService::class, 'noDoc', new McpTool());
        });
    }

    public function testBootErrors(): void {
        $cases = [
            'untyped array input' => fn() => $this->def(McpDefService::class, 'untypedArray', new McpTool(description: 'd')),
            'invalid name' => fn() => $this->def(McpDefService::class, 'noDoc', new McpTool(description: 'd', name: 'bad name')),
            'httpMethod on service' => fn() => $this->def(McpDefService::class, 'noDoc', new McpTool(description: 'd', httpMethod: 'GET')),
            'multi-method mapping without httpMethod' => fn() => McpToolDefinitionFactory::create(
                new ReflectionMethod(McpDefMultiController::class, 'multi'),
                new McpTool(description: 'd'),
                $this->mappingOf(McpDefMultiController::class, 'multi')
            ),
            'httpMethod not in mapping' => fn() => McpToolDefinitionFactory::create(
                new ReflectionMethod(McpDefMultiController::class, 'multi'),
                new McpTool(description: 'd', httpMethod: 'DELETE'),
                $this->mappingOf(McpDefMultiController::class, 'multi')
            ),
            'REST method without mapping' => fn() => McpToolDefinitionFactory::create(
                new ReflectionMethod(McpDefMultiController::class, 'unmapped'),
                new McpTool(description: 'd'),
                null
            ),
            'inputSchema with wrong properties' => fn() => $this->def(McpDefService::class, 'scalar', new McpTool(
                description: 'd',
                inputSchema: ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]
            )),
            'inputSchema missing a required argument' => fn() => $this->def(McpDefService::class, 'scalar', new McpTool(
                description: 'd',
                inputSchema: ['type' => 'object', 'properties' => [
                    'a' => ['type' => 'integer'], 'b' => ['type' => 'string'], 'c' => ['type' => 'string'],
                ]]
            )),
            'redundant outputType' => fn() => $this->def(McpDefService::class, 'returnsView', new McpTool(
                description: 'd',
                outputType: McpDefView::class
            )),
        ];
        foreach ($cases as $label => $fn) {
            $this->assertThrows(McpDefinitionException::class, $fn, $label);
        }
    }

    public function testDocArrayInputAndExplicitOverride(): void {
        $t = $this->def(McpDefService::class, 'docArray', new McpTool(description: 'd'));
        $this->assertSame(['type' => 'string'], $t->inputSchema['properties']['filters']['additionalProperties']);

        $override = ['type' => 'object', 'properties' => ['filters' => ['type' => 'object']], 'required' => ['filters']];
        $t = $this->def(McpDefService::class, 'untypedArray', new McpTool(description: 'd', inputSchema: $override));
        $this->assertSame($override, $t->inputSchema);
    }

    public function testRestDtoReturnHasNoDerivedOutputSchema(): void {
        $t = McpToolDefinitionFactory::create(
            new ReflectionMethod(McpDefMultiController::class, 'typed'),
            new McpTool(description: 'd'),
            $this->mappingOf(McpDefMultiController::class, 'typed')
        );
        $this->assertNull($t->outputSchema, 'a REST body is json_encode()d; its DTO shape is not reliable');
    }

    public function testDuplicateNamesFailAtBoot(): void {
        $registry = new McpToolRegistry();
        $registry->add($this->def(McpDefService::class, 'noDoc', new McpTool(description: 'd', name: 'same')));
        $this->assertThrows(McpDefinitionException::class, function () use ($registry) {
            $registry->add($this->def(McpDefService::class, 'summaryOnly', new McpTool(name: 'same')));
        });
    }

    public function testAttributeInitRejectsBadTargets(): void {
        $this->assertThrows(McpDefinitionException::class, function () {
            (new McpTool(description: 'x'))->init(RefMethod::getInstance(new ReflectionMethod(NoStereotype::class, 'tool')));
        }, 'class without stereotype');
        $this->assertThrows(McpDefinitionException::class, function () {
            (new McpTool(description: 'x'))->init(RefMethod::getInstance(new ReflectionMethod(McpDefService::class, 'staticTool')));
        }, 'static method');
        $this->assertThrows(McpDefinitionException::class, function () {
            (new McpTool(description: 'x', readOnly: true, destructive: true))
                ->init(RefMethod::getInstance(new ReflectionMethod(McpDefService::class, 'noDoc')));
        }, 'contradicting hints');
        $this->assertThrows(McpDefinitionException::class, function () {
            (new McpTool(description: 'x', outputSchema: ['type' => 'object'], outputType: McpDefView::class))
                ->init(RefMethod::getInstance(new ReflectionMethod(McpDefService::class, 'noDoc')));
        }, 'outputSchema with outputType');
        $this->assertThrows(McpDefinitionException::class, function () {
            (new McpTool(description: 'x', inputSchema: ['type' => 'array']))
                ->init(RefMethod::getInstance(new ReflectionMethod(McpDefService::class, 'noDoc')));
        }, 'non-object input schema');
    }
}
