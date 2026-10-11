<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\app\WinterWebSwooleApplication;
use dev\winterframework\io\timer\IdleCheckRegistry;
use ReflectionMethod;
use winterBootTests\Support\TestCase;

/**
 * A Swoole timer created in the master during boot creates the event loop,
 * and Swoole >= 6.2 then refuses to start the HTTP server ("The event-loop
 * has already been created"). Boot-time registration must not start it.
 */
class IdleCheckBootTimerTest extends TestCase {

    public function testRegisterDoesNotStartTheTimer(): void {
        $registry = new IdleCheckRegistry();
        $registry->register(static function (): void {
        });
        $this->assertFalse($registry->isTimerActive());
    }

    public function testSwooleWebApplicationStartsNoTimerInTheMaster(): void {
        $method = new ReflectionMethod(WinterWebSwooleApplication::class, 'startIdleCheckTimer');
        $this->assertTrue($method->getDeclaringClass()->getName() === WinterWebSwooleApplication::class);
    }
}
