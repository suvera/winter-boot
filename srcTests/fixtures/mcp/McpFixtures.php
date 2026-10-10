<?php
declare(strict_types=1);

namespace winterBootTests\Fixtures\Mcp;

use DateTimeImmutable;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use winterBootTests\Fixtures\Mcp\Sub\ImportedDto as AliasedDto;

enum OrderStatus: string {
    case Open = 'open';
    case Paid = 'paid';
}

enum Carrier {
    case Dhl;
    case Ups;
}

class Address {
    /** Street and house number. */
    public string $street;
    public string $city;
    public ?string $postcode = null;
}

class OrderLine {
    public string $sku;
    /** Number of units. */
    public int $quantity = 1;
}

class CreateOrder {
    public string $customerId;

    #[JsonProperty(listClass: OrderLine::class)]
    public array $lines = [];

    #[JsonProperty]
    public Address $shipTo;

    /**
     * Free-form labels.
     * @var array<string, string>
     */
    public array $labels = [];

    #[JsonProperty('deliver_after')]
    public ?DateTimeImmutable $deliverAfter = null;

    private string $internal = 'hidden';
}

class OrderView {
    public int $id;
    public OrderStatus $status;
    public DateTimeImmutable $createdAt;
    /** @var list<OrderLine> */
    public array $lines = [];
    public ?string $note = null;
}

class CategoryNode {
    public string $slug;
    #[JsonProperty(listClass: CategoryNode::class)]
    public array $children = [];
}

class Untyped {
    public array $bag = [];
}

class Deep1 { public Deep2 $n; }
class Deep2 { public Deep3 $n; }
class Deep3 { public Deep4 $n; }
class Deep4 { public Deep5 $n; }
class Deep5 { public Deep6 $n; }
class Deep6 { public Deep7 $n; }
class Deep7 { public Deep8 $n; }
class Deep8 { public Deep9 $n; }
class Deep9 { public int $x; }

#[RestController]
#[RequestMapping(path: '/api/orders')]
class OrderController {

    /** @var array<int, array{method: string, args: array}> */
    public static array $calls = [];

    /**
     * Fetch one order.
     *
     * @param int $id Order number.
     */
    #[GetMapping(path: '/{id}')]
    #[McpTool]
    public function get(#[PathVariable] int $id): array {
        self::$calls[] = ['method' => 'get', 'args' => [$id]];
        return ['id' => $id, 'status' => 'open'];
    }

    /**
     * Search orders.
     *
     * @param string|null $status Only this status.
     * @param int $limit Max rows.
     * @return list<array{id: int, status: string}>
     */
    #[GetMapping(path: '/search')]
    #[McpTool(name: 'search_orders', title: 'Search orders')]
    public function search(
        #[RequestParam(source: 'header', name: 'X-Tenant')] string $tenant,
        #[RequestParam(required: false)] ?string $status = null,
        #[RequestParam(required: false)] int $limit = 20,
    ): array {
        self::$calls[] = ['method' => 'search', 'args' => [$tenant, $status, $limit]];
        return [['id' => 1, 'status' => $status ?? 'open']];
    }

    #[PostMapping(path: '/')]
    #[McpTool(description: 'Create an order.', name: 'create_order', outputType: OrderView::class, destructive: false)]
    public function create(#[RequestBody] CreateOrder $order): ResponseEntity {
        self::$calls[] = ['method' => 'create', 'args' => [$order]];
        return ResponseEntity::ok()->withJson([
            'id' => 7,
            'status' => 'open',
            'createdAt' => '2026-10-10T00:00:00+00:00',
            'lines' => [],
            'note' => null,
        ]);
    }

    #[RequestMapping(path: '/{id}/cancel', method: [RequestMethod::POST, RequestMethod::PUT])]
    #[McpTool(description: 'Cancel an order.', name: 'cancel_order', httpMethod: RequestMethod::PUT)]
    public function cancel(#[PathVariable] int $id, HttpRequest $request): array {
        self::$calls[] = ['method' => 'cancel', 'args' => [$id, $request->getMethod()]];
        return ['cancelled' => $id];
    }

    #[GetMapping(path: '/boom')]
    #[McpTool(description: 'Always fails.')]
    public function boom(): array {
        throw new \RuntimeException('secret failure detail');
    }
}

#[Service]
class ShippingService {

    /**
     * Track parcels.
     *
     * @param list<string> $numbers Tracking numbers.
     * @param AliasedDto|null $extra Extra options.
     * @return array<string, string> Number => status.
     */
    #[McpTool(title: 'Track parcels', readOnly: true, openWorld: true)]
    public function track(
        Carrier $carrier,
        array $numbers,
        HttpRequest $request,
        McpToolContext $ctx,
        ?AliasedDto $extra = null,
        OrderStatus $status = OrderStatus::Open,
    ): array {
        $out = [];
        foreach ($numbers as $n) {
            $out[$n] = $carrier->name . ':' . $status->value . ':' . $ctx->getToolName();
        }
        return $out;
    }

    #[McpTool(description: 'Category tree.', readOnly: true)]
    public function tree(): CategoryNode {
        $n = new CategoryNode();
        $n->slug = 'root';
        return $n;
    }

    #[McpTool(description: 'Count things.')]
    public function count(int $a, float $b = 1.5): int {
        return $a;
    }

    #[McpTool(description: 'Returns nothing.')]
    public function noop(): void {
    }
}

#[Component]
class NotAnEndpoint {
}

class NoStereotype {
    #[McpTool(description: 'x')]
    public function tool(): void {
    }
}

#[RestController]
#[RequestMapping(path: '/api/admin')]
class AdminController implements \dev\winterframework\core\web\ControllerInterceptor {

    public static int $statsCalls = 0;

    public function preHandle(HttpRequest $request, ResponseEntity $response, \ReflectionMethod $handler): bool {
        if ($request->getFirstHeader('X-Role') === 'admin') {
            return true;
        }
        if ($request->getFirstHeader('X-Silent-Veto') !== null) {
            return false; // refuses without setting a status: the response stays 200
        }
        $response->withStatus(\dev\winterframework\web\http\HttpStatus::$FORBIDDEN);
        return false;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response, \ReflectionMethod $handler): void {
    }

    #[GetMapping(path: '/stats')]
    #[McpTool(description: 'Admin statistics.')]
    public function stats(): array {
        self::$statsCalls++;
        return ['orders' => 10];
    }
}
