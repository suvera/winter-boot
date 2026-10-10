<?php

declare(strict_types=1);

namespace winterBootTests;

require_once __DIR__ . '/fixtures/mcp/Sub/ImportedDto.php';
require_once __DIR__ . '/fixtures/mcp/McpFixtures.php';

use dev\winterframework\mcp\exception\McpSchemaException;
use dev\winterframework\mcp\schema\McpSchemaDeriver;
use dev\winterframework\mcp\schema\McpSchemaValidator;
use dev\winterframework\mcp\schema\PhpDocTypeParser;
use ReflectionClass;
use winterBootTests\Fixtures\Mcp\CategoryNode;
use winterBootTests\Fixtures\Mcp\CreateOrder;
use winterBootTests\Fixtures\Mcp\Deep1;
use winterBootTests\Fixtures\Mcp\OrderController;
use winterBootTests\Fixtures\Mcp\OrderView;
use winterBootTests\Fixtures\Mcp\ShippingService;
use winterBootTests\Fixtures\Mcp\Sub\ImportedDto;
use winterBootTests\Support\TestCase;

/**
 * MCP schema derivation: the PHPDoc subset, PHP type -> JSON Schema,
 * and the strict argument validator.
 */
final class McpSchemaTest extends TestCase {

    private function ctx(): ReflectionClass {
        return new ReflectionClass(ShippingService::class);
    }

    private function schemaOf(string $phpDocType, bool $input = true): array {
        $d = $input ? McpSchemaDeriver::forInput() : McpSchemaDeriver::forOutput();
        return $d->nodeSchema(PhpDocTypeParser::parse($phpDocType, $this->ctx()), 'x');
    }

    // ------------------------------------------------------------- PhpDoc

    public function testDocSummaryIsFirstParagraphBeforeTags(): void {
        $doc = "/**\n * Fetch one order\n * with lines.\n *\n * More detail.\n * @param int \$id Order number.\n */";
        $this->assertSame('Fetch one order with lines.', PhpDocTypeParser::summary($doc));
        $this->assertSame('', PhpDocTypeParser::summary(false));
    }

    public function testDocParamsKeepTypesWithSpacesAndDescriptions(): void {
        $doc = "/**\n * @param array<string, int> \$map The map\n *        continued.\n"
            . " * @param ?string \$q Query.\n * @param int|null \$n\n */";
        $p = PhpDocTypeParser::params($doc);
        $this->assertSame('array<string, int>', $p['map']['type']);
        $this->assertSame('The map continued.', $p['map']['description']);
        $this->assertSame('?string', $p['q']['type']);
        $this->assertSame('int|null', $p['n']['type']);
    }

    public function testDocParserForms(): void {
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $this->schemaOf('list<int>'));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $this->schemaOf('string[]'));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'number']], $this->schemaOf('array<int, float>'));
        $this->assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
            $this->schemaOf('array<string, bool>')
        );
        $this->assertSame(
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'string']],
                'required' => ['a'], 'additionalProperties' => false],
            $this->schemaOf('array{a: int, b?: string}')
        );
        $this->assertSame(['type' => ['integer', 'null']], $this->schemaOf('?int'));
        $this->assertSame(['type' => ['string', 'null']], $this->schemaOf('string|null'));
        $this->assertSame(
            ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'], 'additionalProperties' => false]],
            $this->schemaOf('list<array{id: int}>')
        );
    }

    public function testDocParserUnknownFormsAreUntyped(): void {
        foreach (['array', 'mixed', 'array<Foo\\Missing>', 'list<int', 'callable(int): void', 'array<bool, int>'] as $t) {
            $node = PhpDocTypeParser::parse($t, $this->ctx());
            $this->assertTrue(PhpDocTypeParser::hasUntyped($node), $t);
        }
    }

    public function testDocClassNamesResolveThroughImportsAndNamespace(): void {
        $ctx = $this->ctx();
        $this->assertSame(['kind' => 'class', 'name' => ImportedDto::class],
            PhpDocTypeParser::parse('AliasedDto', $ctx), 'aliased import');
        $this->assertSame(['kind' => 'class', 'name' => OrderView::class],
            PhpDocTypeParser::parse('OrderView', $ctx), 'same namespace');
        $this->assertSame(['kind' => 'class', 'name' => OrderView::class],
            PhpDocTypeParser::parse('\\' . OrderView::class, null), 'fully qualified');
    }

    public function testParseImportsHandlesGroupsAliasesAndSkipsFunctions(): void {
        $code = "<?php\nnamespace A;\nuse B\\C;\nuse D\\{E, F as G};\nuse function h\\i;\n"
            . "class X { use SomeTrait; }\n";
        $this->assertSame(['c' => 'B\\C', 'e' => 'D\\E', 'g' => 'D\\F'], PhpDocTypeParser::parseImports($code));
    }

    // ------------------------------------------------------------ deriver

    public function testDtoSchemaUsesJsonNamesListClassAndPhpDoc(): void {
        $d = McpSchemaDeriver::forInput();
        $ref = $d->valueSchema(null, '\\' . CreateOrder::class, $this->ctx(), 'body');
        $this->assertSame(['$ref' => '#/$defs/CreateOrder'], $ref);
        $defs = $d->getDefs();
        $order = $defs['CreateOrder'];
        $this->assertSame(['customerId', 'lines', 'shipTo', 'labels', 'deliver_after'], array_keys($order['properties']));
        $this->assertSame(['customerId', 'shipTo'], $order['required']);
        $this->assertSame(['$ref' => '#/$defs/OrderLine'], $order['properties']['lines']['items']);
        $this->assertSame(['type' => 'string'], $order['properties']['labels']['additionalProperties']);
        $this->assertSame('Free-form labels.', $order['properties']['labels']['description']);
        $this->assertSame(['string', 'null'], $order['properties']['deliver_after']['type']);
        $this->assertSame('date-time', $order['properties']['deliver_after']['format']);
        $this->assertFalse(isset($order['properties']['internal']), 'private property without JsonProperty');
        $this->assertSame(['sku'], $defs['OrderLine']['required']);
        $this->assertSame(1, $defs['OrderLine']['properties']['quantity']['default']);
    }

    public function testRecursiveRootUsesHashRef(): void {
        $d = McpSchemaDeriver::forOutput();
        $schema = $d->rootObjectSchema(CategoryNode::class, 'return');
        $this->assertSame(['$ref' => '#'], $schema['properties']['children']['items']);
        $this->assertSame([], $d->getDefs());
    }

    public function testEnumsAndDates(): void {
        $d = McpSchemaDeriver::forOutput();
        $schema = $d->rootObjectSchema(OrderView::class, 'return');
        $this->assertSame(['type' => 'string', 'enum' => ['open', 'paid']], $schema['properties']['status']);
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $schema['properties']['createdAt']);
        $this->assertSame(['$ref' => '#/$defs/OrderLine'], $schema['properties']['lines']['items']);
    }

    public function testDepthCapFailsClosed(): void {
        $this->assertThrows(McpSchemaException::class, function () {
            McpSchemaDeriver::forInput()->valueSchema(null, '\\' . Deep1::class, $this->ctx(), 'x');
        });
    }

    public function testUntypedArrayCannotBeDescribed(): void {
        $this->assertThrows(McpSchemaException::class, function () {
            McpSchemaDeriver::forInput()->nodeSchema(['kind' => 'untyped'], 'parameter $bag');
        });
    }

    public function testRestOutputRejectsDtosButKeepsBackedEnums(): void {
        $d = McpSchemaDeriver::forRestOutput();
        $this->assertThrows(McpSchemaException::class, function () use ($d) {
            $d->valueSchema(null, 'list<\\' . OrderView::class . '>', $this->ctx(), 'return');
        });
        $schema = McpSchemaDeriver::forRestOutput()->valueSchema(
            null,
            'list<\\winterBootTests\\Fixtures\\Mcp\\OrderStatus>',
            $this->ctx(),
            'return'
        );
        $this->assertSame(['open', 'paid'], $schema['items']['enum']);
    }

    public function testPhpTypeWinsUnlessVague(): void {
        $method = new \ReflectionMethod(OrderController::class, 'get');
        $param = $method->getParameters()[0];
        // int stays int even if PHPDoc claims a string
        $schema = McpSchemaDeriver::forInput()->valueSchema($param->getType(), 'string', $this->ctx(), 'p');
        $this->assertSame(['type' => 'integer'], $schema);
    }

    public function testNullableWrapsRefsInAnyOf(): void {
        $this->assertSame(
            ['anyOf' => [['$ref' => '#/$defs/X'], ['type' => 'null']]],
            McpSchemaDeriver::nullable(['$ref' => '#/$defs/X'])
        );
        $this->assertSame(
            ['type' => ['string', 'null'], 'enum' => ['a', null]],
            McpSchemaDeriver::nullable(['type' => 'string', 'enum' => ['a']])
        );
    }

    // ---------------------------------------------------------- validator

    public function testValidatorTypesRequiredAndUnknownProperties(): void {
        $schema = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 0],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['id'], 'additionalProperties' => false];

        $this->assertNull(McpSchemaValidator::validate(['id' => 3, 'tags' => ['a']], $schema));
        $this->assertNull(McpSchemaValidator::validate(['id' => 3.0], $schema), 'integral float is an integer');
        $this->assertSame('id: is required', McpSchemaValidator::validate([], $schema));
        $this->assertSame('id: expected integer', McpSchemaValidator::validate(['id' => '3'], $schema));
        $this->assertSame('id: must be >= 0', McpSchemaValidator::validate(['id' => -1], $schema));
        $this->assertSame('tags[1]: expected string', McpSchemaValidator::validate(['id' => 1, 'tags' => ['a', 2]], $schema));
        $this->assertSame('arguments: unknown property "evil"', McpSchemaValidator::validate(['id' => 1, 'evil' => 1], $schema));
    }

    public function testValidatorNeverEchoesValuesAndCleansKeys(): void {
        $schema = ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'enum' => ['a']]],
            'additionalProperties' => false];
        $err = McpSchemaValidator::validate(['s' => 'SECRET-VALUE'], $schema);
        $this->assertSame('s: must be one of "a"', $err);
        $err = McpSchemaValidator::validate(["k\n" . str_repeat('x', 100) => 1], $schema);
        $this->assertTrue(!str_contains((string)$err, "\n") && strlen((string)$err) < 120, (string)$err);
    }

    public function testValidatorFollowsRefsAndAnyOf(): void {
        $d = McpSchemaDeriver::forInput();
        $ref = $d->valueSchema(null, '\\' . CreateOrder::class, $this->ctx(), 'body');
        $schema = ['type' => 'object', 'properties' => ['order' => $ref], 'required' => ['order'],
            '$defs' => $d->getDefs()];
        $good = ['order' => ['customerId' => 'c', 'shipTo' => ['street' => 's', 'city' => 'c'],
            'lines' => [['sku' => 'x', 'quantity' => 2]]]];
        $this->assertNull(McpSchemaValidator::validate($good, $schema));
        $bad = $good;
        $bad['order']['lines'][0]['quantity'] = 'two';
        $this->assertSame('order.lines[0].quantity: expected integer', McpSchemaValidator::validate($bad, $schema));

        $anyOf = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $this->assertNull(McpSchemaValidator::validate(null, $anyOf));
        $this->assertSame('arguments: does not match any allowed form', McpSchemaValidator::validate(1, $anyOf));
    }

    public function testValidatorAcceptsHashRootRef(): void {
        $schema = McpSchemaDeriver::forOutput()->rootObjectSchema(CategoryNode::class, 'r');
        $this->assertNull(McpSchemaValidator::validate(
            ['slug' => 'a', 'children' => [['slug' => 'b', 'children' => []]]],
            $schema
        ));
        $this->assertSame(
            'children[0].slug: expected string',
            McpSchemaValidator::validate(['slug' => 'a', 'children' => [['slug' => 1]]], $schema)
        );
    }
}
