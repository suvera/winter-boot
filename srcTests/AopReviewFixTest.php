<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\WinterAopInterceptor;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopContextExecute;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\util\ComponentName;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\type\AttributeList;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;
use winterBootTests\Support\TestCase;

class UnwindAspect implements WinterAspect {
    public function __construct(
        private string $name,
        private array &$log,
        private ?string $beginThrows = null,
        private ?string $handlerThrows = null
    ) {
    }

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        if ($this->beginThrows !== null) {
            throw new RuntimeException($this->beginThrows);
        }
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        /** @var UnwindGuard $guard */
        $guard = $ctx->getStereoType();
        $this->log[] = 'beginFailed:' . $this->name . ':ctx=' . $guard->name . ':' . $ex->getMessage();
        if ($this->handlerThrows !== null) {
            throw new RuntimeException($this->handlerThrows);
        }
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        $this->log[] = 'failed:' . $this->name . ':' . $ex->getMessage();
        if ($this->handlerThrows !== null) {
            throw new RuntimeException($this->handlerThrows);
        }
    }
}

class UnwindGuard implements AopStereoType {
    public function __construct(public string $name, private WinterAspect $aspect) {
    }

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        return $this->aspect;
    }

    public function init(object $ref): void {
    }
}

class OrderEndpoint {
    public function show(int $id, string $view = 'full', ?stdClass $request = null): string {
        return 'never-called';
    }
}

class OrderNameHarness {
    use AopContextExecute;

    public static function name(ComponentName $name, AopContext $ctx, object $target, array $args): string {
        return self::buildNameByContext($name, $ctx, $target, $args);
    }
}

/**
 * Regression tests for the AOP review fixes: begin-failure unwinding pairs
 * each aspect with its own context and the original exception, controller
 * endpoints hand aspects positional arguments, and advice that can never
 * engage on a RestController method is reported.
 */
final class AopReviewFixTest extends TestCase {

    private function appCtx(): WinterApplicationContextBuilder {
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

    /** @param UnwindGuard[] $guards */
    private function interceptor(array $guards): WinterAopInterceptor {
        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance(new ReflectionMethod(OrderEndpoint::class, 'show')));
        $methRes->setAttributes(AttributeList::ofArray($guards));
        return new WinterAopInterceptor(new ClassResource(), $methRes, new ApplicationContextData(), $this->appCtx());
    }

    public function testBeginFailedUnwindsEachAspectWithItsOwnContext(): void {
        $log = [];
        $interceptor = $this->interceptor([
            new UnwindGuard('A', new UnwindAspect('A', $log)),
            new UnwindGuard('B', new UnwindAspect('B', $log, 'orig')),
        ]);

        $this->assertThrows(RuntimeException::class, function () use ($interceptor): void {
            $interceptor->aspectBegin(new AopExecutionContext(new stdClass(), []));
        });

        $this->assertSame([
            'beginFailed:B:ctx=B:orig',
            'beginFailed:A:ctx=A:orig',
        ], $log);
    }

    public function testBeginFailedHandlerErrorDoesNotReplaceOriginal(): void {
        $log = [];
        $interceptor = $this->interceptor([
            new UnwindGuard('A', new UnwindAspect('A', $log)),
            new UnwindGuard('B', new UnwindAspect('B', $log, 'orig', 'handler-boom')),
        ]);

        try {
            @$interceptor->aspectBegin(new AopExecutionContext(new stdClass(), []));
            $this->assertTrue(false, 'aspectBegin should rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame('orig', $e->getMessage());
        }

        $this->assertSame([
            'beginFailed:B:ctx=B:orig',
            'beginFailed:A:ctx=A:orig',
        ], $log);
    }

    public function testFailedHandlerErrorDoesNotReplaceOriginal(): void {
        $log = [];
        $interceptor = $this->interceptor([
            new UnwindGuard('A', new UnwindAspect('A', $log, null, 'handler-boom')),
            new UnwindGuard('B', new UnwindAspect('B', $log)),
        ]);

        @$interceptor->aspectFailed(new AopExecutionContext(new stdClass(), []), new RuntimeException('boom'));

        $this->assertSame(['failed:A:boom', 'failed:B:boom'], $log);
    }

    public function testPositionalArgumentsFollowParameterOrder(): void {
        $method = new ReflectionMethod(OrderEndpoint::class, 'show');
        $req = new stdClass();

        // Dispatcher binds request objects last and path vars first, by name.
        $this->assertSame(
            [0 => 42, 1 => 'full', 2 => $req],
            DispatcherServlet::positionalArguments($method, ['request' => $req, 'id' => 42])
        );
        $this->assertSame(
            [0 => 7, 1 => 'brief', 2 => null],
            DispatcherServlet::positionalArguments($method, ['view' => 'brief', 'id' => 7])
        );
    }

    public function testControllerTemplateResolvesArgument(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        $method = new ReflectionMethod(OrderEndpoint::class, 'show');
        $ctx = new AopContext(new InlineGuardStub(), RefMethod::getInstance($method), $this->appCtx());

        $out = OrderNameHarness::name(
            new ComponentName('order-#{id}'),
            $ctx,
            new OrderEndpoint(),
            DispatcherServlet::positionalArguments($method, ['view' => 'full', 'id' => 42])
        );
        $this->assertSame('order-42', $out);
    }

    public function testIgnoredControllerAdvice(): void {
        $lock = new Lockable(name: 'x');

        $this->assertSame(['Lockable'], ClassResourceScanner::ignoredControllerAdvice([$lock]));
        $this->assertSame([], ClassResourceScanner::ignoredControllerAdvice([new GetMapping('a'), $lock]));
        $this->assertSame([], ClassResourceScanner::ignoredControllerAdvice([new Scheduled(fixedDelay: 5)]));
        $this->assertSame([], ClassResourceScanner::ignoredControllerAdvice([new GetMapping('a')]));
    }
}

class InlineGuardStub implements AopStereoType {
    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        throw new RuntimeException('unused');
    }

    public function init(object $ref): void {
    }
}
