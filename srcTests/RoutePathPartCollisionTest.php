<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\PutMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use winterBootTests\Support\TestCase;

#[RestController]
class PathCollisionController {
    #[GetMapping(path: '/rt-collide/staff/{staff}')]
    public function showStaff(#[PathVariable] int $staff): void {
    }

    #[PutMapping(path: '/rt-collide/staff/{staff}')]
    public function updateStaff(#[PathVariable] int $staff): void {
    }

    #[GetMapping(path: '/rt-collide/x/items/x')]
    public function repeatedLiteral(): void {
    }
}

#[RestController]
class RepeatedVariableController {
    #[GetMapping(path: '/rt-collide/{id}/child/{id}')]
    public function repeated(#[PathVariable] int $id): void {
    }
}

#[RestController]
#[RequestMapping(path: '/rt-collide-api')]
class PrefixCollisionController {
    #[GetMapping(path: '/rt-collide-api/{id}')]
    public function show(#[PathVariable] int $id): void {
    }
}

/**
 * Path parts were keyed by their text, so a variable named like a literal
 * segment ("staff/{staff}") replaced that segment (the route registered as
 * "VAR_REGEX" one level up), and a repeated literal collapsed into one.
 */
final class RoutePathPartCollisionTest extends TestCase {
    public function testVariableNamedLikeALiteralSegmentKeepsBothParts(): void {
        $reg = $this->registry(['showStaff', 'updateStaff', 'repeatedLiteral']);

        $this->assertSame('showStaff', $this->owner($reg, 'rt-collide/staff/7', 'GET'));
        $this->assertSame('updateStaff', $this->owner($reg, 'rt-collide/staff/7', 'PUT'));
        $this->assertNull($this->owner($reg, 'rt-collide/7', 'GET'));
    }

    public function testRepeatedLiteralSegmentsKeepTheirOrder(): void {
        $reg = $this->registry(['repeatedLiteral']);

        $this->assertSame('repeatedLiteral', $this->owner($reg, 'rt-collide/x/items/x', 'GET'));
        $this->assertNull($this->owner($reg, 'rt-collide/x/items', 'GET'));
    }

    public function testMethodPathRepeatingTheClassPrefixKeepsBothParts(): void {
        $reg = $this->registry(['show'], PrefixCollisionController::class);

        $this->assertSame('show', $this->owner($reg, 'rt-collide-api/rt-collide-api/7', 'GET'));
        $this->assertNull($this->owner($reg, 'rt-collide-api/7', 'GET'));
    }

    public function testRepeatedPathVariableIsRejected(): void {
        $ref = RefMethod::getInstance(new \ReflectionMethod(RepeatedVariableController::class, 'repeated'));
        $this->assertThrows(InvalidSyntaxException::class, function () use ($ref): void {
            foreach ($ref->getAttributes(GetMapping::class) as $a) {
                $a->newInstance()->init($ref);
            }
        });
    }

    /** @param list<string> $methods */
    private function registry(array $methods, string $class = PathCollisionController::class): WinterRequestMappingRegistry {
        $reg = (new \ReflectionClass(WinterRequestMappingRegistry::class))->newInstanceWithoutConstructor();
        foreach ($methods as $method) {
            $ref = RefMethod::getInstance(new \ReflectionMethod($class, $method));
            foreach ([GetMapping::class, PutMapping::class] as $attr) {
                foreach ($ref->getAttributes($attr) as $a) {
                    $inst = $a->newInstance();
                    $inst->init($ref);
                    $reg->put($inst);
                }
            }
        }
        return $reg;
    }

    private function owner(WinterRequestMappingRegistry $reg, string $path, string $method): ?string {
        $hit = $reg->find($path, $method);
        return $hit?->getMapping()->getRefOwner()->getName();
    }
}
