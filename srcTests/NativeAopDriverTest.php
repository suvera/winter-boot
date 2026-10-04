<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\aop\NativeAopDriver;
use dev\winterframework\core\aop\WinterAopInterceptor;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\AttributeList;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;
use winterBootTests\Support\TestCase;

class ThrowingAspect implements WinterAspect {
    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        throw new RuntimeException('begin-boom');
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
    }

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void {
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }
}

class ThrowingGuard implements AopStereoType {
    public function __construct(private ThrowingAspect $aspect) {
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

class FakeAsyncExecutor {
    /** @var array<int, array{0: string, 1: string, 2: ?array}> */
    public array $enqueued = [];

    public function enqueue(string $className, string $methodName, ?array $args = null): void {
        $this->enqueued[] = [$className, $methodName, $args];
    }
}

/**
 * NativeAopDriver replays the generated-proxy protocol in pure PHP (no
 * extension needed): begin()/finish() drive the same aspectBegin/commit/
 * failed sequence, async methods enqueue under their real name, and the
 * worker bypass token is consumed exactly once.
 */
final class NativeAopDriverTest extends TestCase {

    /** @return array{0: AopInterceptorRegistry, 1: WinterApplicationContextBuilder} */
    private function bootDriver(
        object $guard,
        string $methodName = 'target',
        bool $async = false,
        ?FakeAsyncExecutor $executor = null
    ): array {
        $ctxData = new ApplicationContextData();
        $appCtx = new class ($executor) extends WinterApplicationContextBuilder {
            public function __construct(private readonly ?FakeAsyncExecutor $executor) {
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

            public function beanByClass(string $class): ?object {
                return $this->executor;
            }
        };

        $classRes = new ClassResource();
        $classRes->setClass(RefKlass::getInstance(RecordingTarget::class));

        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance(new ReflectionMethod(RecordingTarget::class, 'target')));
        $methRes->setAttributes(AttributeList::ofArray([$guard]));
        $methRes->setAsyncProxy($async);

        $registry = new AopInterceptorRegistry($ctxData, $appCtx);
        $registry->register($classRes, $methRes);

        NativeAopDriver::boot($registry, $appCtx);
        return [$registry, $appCtx];
    }

    public function testBeginProceedAndFinishCommit(): void {
        $aspect = new RecordingAspect(false);
        [$registry] = $this->bootDriver(new RecordingGuard($aspect));
        try {
            $res = NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', []);
            $this->assertTrue($res['proceed']);
            $this->assertTrue($res['exCtx'] instanceof AopExecutionContext);
            $this->assertTrue($res['interceptor'] instanceof WinterAopInterceptor);

            NativeAopDriver::finish($res['exCtx'], $res['interceptor'], 'returned', 'ok');

            $this->assertSame(['begin', "commit:'ok'"], $aspect->calls);
            $this->assertSame('ok', $res['exCtx']->getResult());
            $this->assertTrue($res['exCtx']->isSuccess());
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testBeginStopSkipsBody(): void {
        $aspect = new RecordingAspect(true);
        $this->bootDriver(new RecordingGuard($aspect));
        try {
            $res = NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', []);

            $this->assertFalse($res['proceed']);
            $this->assertSame('STOPPED', $res['value']);
            $this->assertSame(['begin', 'beginFailed'], $aspect->calls);
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testBeginFailurePropagates(): void {
        $this->bootDriver(new ThrowingGuard(new ThrowingAspect()));
        try {
            $this->assertThrows(
                RuntimeException::class,
                function (): void {
                    NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', []);
                }
            );
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testFinishThrewRunsFailed(): void {
        $aspect = new RecordingAspect(false);
        $this->bootDriver(new RecordingGuard($aspect));
        try {
            $res = NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', []);
            $this->assertTrue($res['proceed']);

            $ex = new RuntimeException('boom');
            NativeAopDriver::finish($res['exCtx'], $res['interceptor'], 'threw', $ex);

            $this->assertSame(['begin', 'failed:boom'], $aspect->calls);
            $this->assertTrue($res['exCtx']->isFailed());
            $this->assertSame($ex, $res['exCtx']->getException());
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testAsyncEnqueuesRealNameAndBypassRunsOnce(): void {
        $aspect = new RecordingAspect(false);
        $executor = new FakeAsyncExecutor();
        $this->bootDriver(new RecordingGuard($aspect), 'target', true, $executor);
        try {
            // Worker re-entry: the single-shot token runs the body (aspects
            // included) instead of re-enqueueing, and is consumed.
            NativeAopDriver::bypassOnce(RecordingTarget::class, 'target');
            $res = NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', ['a' => 1]);

            $this->assertTrue($res['proceed']);
            $this->assertSame(['begin'], $aspect->calls);
            $this->assertSame([], $executor->enqueued);

            // Next call enqueues under the real name and skips the body.
            $res2 = NativeAopDriver::begin(new stdClass(), RecordingTarget::class, 'target', ['a' => 1]);

            $this->assertFalse($res2['proceed']);
            $this->assertNull($res2['value']);
            $this->assertSame(
                [[RecordingTarget::class, 'target', ['a' => 1]]],
                $executor->enqueued
            );
            $this->assertSame(['begin'], $aspect->calls);
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testNativeFlagDefaultsOff(): void {
        $this->assertFalse(NativeAopDriver::isNativeActive());
        NativeAopDriver::setNativeActive(true);
        try {
            $this->assertTrue(NativeAopDriver::isNativeActive());
        } finally {
            NativeAopDriver::setNativeActive(false);
        }
        $this->assertFalse(NativeAopDriver::isNativeActive());
    }
}
