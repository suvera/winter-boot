<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\DeleteMapping;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use winterBootTests\Support\TestCase;

#[RestController]
class BacktrackTerminalController {
    #[GetMapping(path: '/rt-backtrack-a/tenant/{id}')]
    public function getTenantA(#[PathVariable(name: 'id')] int $id): void {
    }

    #[DeleteMapping(path: '/rt-backtrack-b/tenant/{id}')]
    public function deleteTenantB(#[PathVariable(name: 'id')] int $id): void {
    }
}

#[RestController]
class BacktrackNestedController {
    #[GetMapping(path: '/rt-backtrack-a/tenant/{tenant_id}/unit')]
    public function getUnitsA(#[PathVariable(name: 'tenant_id')] int $tenantId): void {
    }

    #[GetMapping(path: '/rt-backtrack-b/tenant/{tenant_id}/unit')]
    public function getUnitsB(#[PathVariable(name: 'tenant_id')] int $tenantId): void {
    }
}

/**
 * Sibling placeholders with different names ({id} vs {tenant_id})
 * compile to different trie keys at the same level. Route matching
 * must backtrack across them: whichever registers first, both the
 * terminal route and the deeper nested route have to resolve.
 * (Greedy first-match stranded one side with a 404.)
 */
final class RouteBacktrackTest extends TestCase {
    private function registry(): WinterRequestMappingRegistry {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))
            ->newInstanceWithoutConstructor();
        // Prefix A registers terminal-before-nested, prefix B nested-before-terminal.
        $this->put($reg, BacktrackTerminalController::class, 'getTenantA');
        $this->put($reg, BacktrackNestedController::class, 'getUnitsA');
        $this->put($reg, BacktrackNestedController::class, 'getUnitsB');
        $this->put($reg, BacktrackTerminalController::class, 'deleteTenantB');
        return $reg;
    }

    private function put(WinterRequestMappingRegistry $reg, string $class, string $method): void {
        $ref = RefMethod::getInstance(new \ReflectionMethod($class, $method));
        foreach (['GetMapping', 'DeleteMapping'] as $attr) {
            $fqcn = 'dev\\winterframework\\stereotype\\web\\' . $attr;
            foreach ($ref->getAttributes($fqcn) as $a) {
                $inst = $a->newInstance();
                $inst->init($ref);
                $reg->put($inst);
            }
        }
    }

    private function ownerMethod(WinterRequestMappingRegistry $reg, string $path, string $method): ?string {
        $hit = $reg->find($path, $method);
        if ($hit === null) {
            return null;
        }
        return $hit->getMapping()->getRefOwner()->getName();
    }

    public function testTerminalBeforeNestedOrder(): void {
        $reg = $this->registry();
        $this->assertSame('getTenantA', $this->ownerMethod($reg, 'rt-backtrack-a/tenant/1', 'GET'));
        $this->assertSame('getUnitsA', $this->ownerMethod($reg, 'rt-backtrack-a/tenant/1/unit', 'GET'));
    }

    public function testNestedBeforeTerminalOrder(): void {
        $reg = $this->registry();
        $this->assertSame('deleteTenantB', $this->ownerMethod($reg, 'rt-backtrack-b/tenant/1', 'DELETE'));
        $this->assertSame('getUnitsB', $this->ownerMethod($reg, 'rt-backtrack-b/tenant/1/unit', 'GET'));
    }

    public function testUnknownPathStillMisses(): void {
        $reg = $this->registry();
        $this->assertNull($this->ownerMethod($reg, 'rt-backtrack-a/tenant', 'DELETE'));
        $this->assertNull($this->ownerMethod($reg, 'rt-backtrack-a/nope', 'GET'));
    }
}
