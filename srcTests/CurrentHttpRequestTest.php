<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\web\http\HttpRequest;
use winterBootTests\Support\TestCase;

/**
 * ApplicationContext must expose the request being dispatched via
 * getCurrentHttpRequest(), and null when none is in flight.
 */
final class CurrentHttpRequestTest extends TestCase {

    private function newContext(): WinterApplicationContextBuilder {
        return new class extends WinterApplicationContextBuilder {
            public function __construct() {
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
        };
    }

    public function testNullWhenUnset(): void {
        $this->assertNull($this->newContext()->getCurrentHttpRequest());
    }

    public function testSetGetClear(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/probe';

        $ctx = $this->newContext();
        $request = new HttpRequest();

        $ctx->setCurrentHttpRequest($request);
        $this->assertTrue($ctx->getCurrentHttpRequest() === $request);

        $ctx->setCurrentHttpRequest(null);
        $this->assertNull($ctx->getCurrentHttpRequest());
    }

    public function testSlotDoesNotPinRequest(): void {
        $ctx = $this->newContext();
        $request = new HttpRequest();
        $ctx->setCurrentHttpRequest($request);
        unset($request);
        gc_collect_cycles();

        $this->assertNull($ctx->getCurrentHttpRequest());
    }

    public function testCoroutineSlotIsolatedFromFallback(): void {
        if (
            !extension_loaded('swoole')
            || !class_exists(\Swoole\Coroutine::class)
            || !method_exists(\Swoole\Coroutine::class, 'run')
        ) {
            $this->assertTrue(true);
            return;
        }

        $ctx = $this->newContext();
        $seen = false;
        \Swoole\Coroutine::run(function () use ($ctx, &$seen): void {
            $request = new HttpRequest();
            $ctx->setCurrentHttpRequest($request);
            $seen = $ctx->getCurrentHttpRequest() === $request;
        });

        $this->assertTrue($seen);
        $this->assertNull($ctx->getCurrentHttpRequest());
    }
}
