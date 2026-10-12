<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use winterBootTests\Support\TestCase;

#[RestController]
#[RequestMapping(path: ['/rt-prefix-a', '/rt-prefix-b'])]
class MultiPrefixController {
    #[GetMapping(path: '/items')]
    public function items(): void {
    }
}

/**
 * Each class-level path prefixed the already-prefixed method paths, so
 * class ['a', 'b'] with method 'items' mapped only 'b/a/items'.
 */
final class RouteClassPathPrefixTest extends TestCase {
    public function testEveryClassPathPrefixesTheMethodPath(): void {
        $ref = RefMethod::getInstance(new \ReflectionMethod(MultiPrefixController::class, 'items'));
        $mapping = $ref->getAttributes(GetMapping::class)[0]->newInstance();
        $mapping->init($ref);

        $this->assertSame(['rt-prefix-a/items', 'rt-prefix-b/items'], array_keys($mapping->getUriPaths()));
    }

    public function testPrefixClassPathsCrossesClassAndMethodPaths(): void {
        $this->assertSame(
            ['a/x', 'a/y', 'b/x', 'b/y'],
            RequestMapping::prefixClassPaths(['/a/', 'b'], ['x', '/y'])
        );
    }

    public function testEmptyClassPathKeepsTheMethodPathUnprefixed(): void {
        $this->assertSame(['x', 'v1/x'], RequestMapping::prefixClassPaths(['/', 'v1'], ['x']));
    }
}
