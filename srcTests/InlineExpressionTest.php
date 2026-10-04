<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\ex\AopException;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopContextExecute;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\util\ComponentName;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use winterBootTests\Support\TestCase;

class InlineGreeter {
    public function __construct(private string $id) {
    }

    public function getId(): string {
        return $this->id;
    }

    public function boom(): string {
        throw new RuntimeException('getter-boom');
    }
}

class InlineFixture {
    public function pick(string $name, int $n): string {
        return 'never-called';
    }

    public function wrap(InlineGreeter $g): string {
        return 'never-called';
    }
}

class InlineGuard implements AopStereoType {
    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        throw new RuntimeException('unused');
    }

    public function init(object $ref): void {
    }
}

class InlineHarness {
    use AopContextExecute;

    public static function inline(string $code, object $target, array $vars): mixed {
        return self::executeInlineCode($code, $target, $vars);
    }

    public static function name(ComponentName $name, AopContext $ctx, object $target, array $args): string {
        return self::buildNameByContext($name, $ctx, $target, $args);
    }
}

/**
 * AOP `#{...}` template expansion runs through winter_boot_exec_inline():
 * no eval() remains in PHP code. Skipped without the extension.
 */
final class InlineExpressionTest extends TestCase {

    private function contextFor(string $method): AopContext {
        $appCtx = new class extends WinterApplicationContextBuilder {
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
        return new AopContext(
            new InlineGuard(),
            RefMethod::getInstance(new ReflectionMethod(InlineFixture::class, $method)),
            $appCtx
        );
    }

    public function testSimpleVarExpands(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        $out = InlineHarness::name(
            new ComponentName('hi-#{name}-#{n}'),
            $this->contextFor('pick'),
            new stdClass(),
            ['bob', 3]
        );
        $this->assertSame('hi-bob-3', $out);
    }

    public function testChainedCallExpands(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        $out = InlineHarness::name(
            new ComponentName('k-#{g.getId()}'),
            $this->contextFor('wrap'),
            new stdClass(),
            [new InlineGreeter('ord-9')]
        );
        $this->assertSame('k-ord-9', $out);
    }

    public function testThrowingGetterWrapsInAopException(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        $ctx = $this->contextFor('wrap');
        $this->assertThrows(
            AopException::class,
            function () use ($ctx): void {
                InlineHarness::name(
                    new ComponentName('k-#{g.boom()}'),
                    $ctx,
                    new stdClass(),
                    [new InlineGreeter('ord-9')]
                );
            }
        );
    }

    public function testSequentialReexpansion(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        // An evaluated value containing a later placeholder re-expands,
        // matching the former back-to-back str_replace() semantics.
        $out = InlineHarness::name(
            new ComponentName('#{name}-#{n}'),
            $this->contextFor('pick'),
            new stdClass(),
            ['#{n}', 5]
        );
        $this->assertSame('5-5', $out);
    }

    public function testMissingVarYieldsEmpty(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        $out = @InlineHarness::name(
            new ComponentName('v-#{n}'),
            $this->contextFor('pick'),
            new stdClass(),
            []
        );
        $this->assertSame('v-', $out);
    }
}
