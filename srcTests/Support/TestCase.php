<?php

declare(strict_types=1);

namespace winterBootTests\Support;

final class AssertionFailed extends \RuntimeException {
}

class TestCase {
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
            );
        }
    }

    protected function assertTrue(mixed $actual, string $message = ''): void {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(mixed $actual, string $message = ''): void {
        $this->assertSame(false, $actual, $message);
    }

    protected function assertNull(mixed $actual, string $message = ''): void {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertThrows(string $expectedClass, callable $fn, string $message = ''): void {
        try {
            $fn();
        } catch (\Throwable $ex) {
            if ($ex instanceof $expectedClass) {
                return;
            }
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '')
                . 'expected exception ' . $expectedClass
                . ', got ' . get_class($ex) . ': ' . $ex->getMessage()
            );
        }
        throw new AssertionFailed(
            ($message !== '' ? $message . ': ' : '')
            . 'expected exception ' . $expectedClass . ', none was thrown'
        );
    }
}
