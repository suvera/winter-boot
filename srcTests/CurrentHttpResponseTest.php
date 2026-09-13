<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\web\http\ResponseEntity;
use winterBootTests\Support\TestCase;

/**
 * ApplicationContext must expose the response being dispatched via
 * getCurrentHttpResponse(), and null when none is in flight.
 */
final class CurrentHttpResponseTest extends TestCase {

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
        $this->assertNull($this->newContext()->getCurrentHttpResponse());
    }

    public function testSetGetClear(): void {
        $ctx = $this->newContext();
        $response = new ResponseEntity();

        $ctx->setCurrentHttpResponse($response);
        $this->assertTrue($ctx->getCurrentHttpResponse() === $response);

        $ctx->setCurrentHttpResponse(null);
        $this->assertNull($ctx->getCurrentHttpResponse());
    }

    public function testSlotDoesNotPinResponse(): void {
        $ctx = $this->newContext();
        $response = new ResponseEntity();
        $ctx->setCurrentHttpResponse($response);
        unset($response);
        gc_collect_cycles();

        $this->assertNull($ctx->getCurrentHttpResponse());
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
            $response = new ResponseEntity();
            $ctx->setCurrentHttpResponse($response);
            $seen = $ctx->getCurrentHttpResponse() === $response;
        });

        $this->assertTrue($seen);
        $this->assertNull($ctx->getCurrentHttpResponse());
    }
}
