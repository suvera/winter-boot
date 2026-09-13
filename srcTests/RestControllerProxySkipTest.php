<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\Psr4Namespaces;
use winterBootTests\Fixtures\ProxySkip\ControllerWithAdvice;
use winterBootTests\Fixtures\ProxySkip\ServiceWithAdvice;
use winterBootTests\Support\TestCase;

/**
 * Controllers are invoked by the dispatcher through the original reflected
 * method (non-virtual), so proxy overrides — and every AOP advice — can
 * never engage on them. The scanner must not flag them for proxying.
 */
final class RestControllerProxySkipTest extends TestCase {

    /** @return array<string, ClassResource> */
    private function scanFixtures(): array {
        $scanner = ClassResourceScanner::getDefaultScanner();
        $ns = Psr4Namespaces::ofArrayItems([
            ['winterBootTests\\Fixtures\\ProxySkip', __DIR__ . '/fixtures/proxy-skip'],
        ]);
        $found = [];
        foreach ($scanner->scan($ns, $scanner->getDefaultStereoTypes(), true, []) as $cls) {
            $found[$cls->getClass()->getName()] = $cls;
        }
        return $found;
    }

    public function testRestControllerSkipsProxy(): void {
        $found = $this->scanFixtures();
        $this->assertTrue(isset($found[ControllerWithAdvice::class]));

        $controller = $found[ControllerWithAdvice::class];
        $this->assertFalse($controller->isProxyNeeded());

        $proxyMethods = 0;
        foreach ($controller->getProxyMethods() as $m) {
            $proxyMethods++;
        }
        $this->assertSame(0, $proxyMethods);

        foreach ($controller->getMethods() as $method) {
            $this->assertFalse($method->isProxyNeeded());
            $this->assertFalse($method->isAopProxy());
        }
    }

    public function testServiceKeepsAopProxy(): void {
        $found = $this->scanFixtures();
        $this->assertTrue(isset($found[ServiceWithAdvice::class]));

        $service = $found[ServiceWithAdvice::class];
        $this->assertTrue($service->isProxyNeeded());

        $guarded = null;
        foreach ($service->getMethods() as $method) {
            if ($method->getMethod()->getShortName() === 'guarded') {
                $guarded = $method;
            }
        }
        $this->assertTrue($guarded !== null);
        $this->assertTrue($guarded->isProxyNeeded());
        $this->assertTrue($guarded->isAopProxy());
    }
}
