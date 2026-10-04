<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\aop\NativeAopDriver;
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
use Throwable;
use winterBootTests\Support\TestCase;

class E2ERecordingAspect implements WinterAspect {
    public array $calls = [];

    public function __construct(private bool $stop = false) {
    }

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        $this->calls[] = 'begin';
        if ($this->stop) {
            $exCtx->stopExecution('STOPPED');
        }
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        $this->calls[] = 'beginFailed';
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        $this->calls[] = 'commit:' . var_export($result, true);
    }

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void {
        $this->calls[] = 'commitFailed';
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        $this->calls[] = 'failed:' . $ex->getMessage();
    }
}

class E2ERecordingGuard implements AopStereoType {
    public function __construct(private E2ERecordingAspect $aspect) {
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

class NativeBean {
    public int $runs = 0;

    public function greet(string $name): string {
        $this->runs++;
        return 'hi ' . $name;
    }

    public function boom(): string {
        throw new RuntimeException('body-boom');
    }

    public function plain(): string {
        return 'plain';
    }
}

/**
 * End-to-end parity: with winter_boot loaded, advised framework beans run
 * the proxy protocol through VM interception — direct calls and reflective
 * invokeArgs (the dispatcher shape) alike. Skipped without the extension.
 */
final class NativeAopEndToEndTest extends TestCase {

    /** @return array{0: E2ERecordingAspect, 1: NativeBean} */
    private function bootAdvised(string $method, bool $stop = false): array {
        $aspect = new E2ERecordingAspect($stop);

        $ctxData = new ApplicationContextData();
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

        $classRes = new ClassResource();
        $classRes->setClass(RefKlass::getInstance(NativeBean::class));

        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance(new ReflectionMethod(NativeBean::class, $method)));
        $methRes->setAttributes(AttributeList::ofArray([new E2ERecordingGuard($aspect)]));

        $registry = new AopInterceptorRegistry($ctxData, $appCtx);
        $registry->register($classRes, $methRes);

        NativeAopDriver::setNativeActive(true);
        NativeAopDriver::boot($registry, $appCtx);
        winter_boot_advise(NativeBean::class, $method);

        return [$aspect, new NativeBean()];
    }

    public function testDirectCallRunsProxyProtocol(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        try {
            [$aspect, $bean] = $this->bootAdvised('greet');

            $this->assertSame('hi bob', $bean->greet('bob'));
            $this->assertSame(['begin', "commit:'hi bob'"], $aspect->calls);
            $this->assertSame(1, $bean->runs);
        } finally {
            NativeAopDriver::setNativeActive(false);
            NativeAopDriver::clearBypass();
        }
    }

    public function testReflectiveInvokeArgsRunsProxyProtocol(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        try {
            [$aspect, $bean] = $this->bootAdvised('greet');
            $method = new ReflectionMethod(NativeBean::class, 'greet');

            $this->assertSame('hi ann', $method->invokeArgs($bean, ['ann']));
            $this->assertSame(['begin', "commit:'hi ann'"], $aspect->calls);
        } finally {
            NativeAopDriver::setNativeActive(false);
            NativeAopDriver::clearBypass();
        }
    }

    public function testStopSkipsBodyNatively(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        try {
            [$aspect, $bean] = $this->bootAdvised('greet', true);

            $this->assertSame('STOPPED', $bean->greet('bob'));
            $this->assertSame(['begin', 'beginFailed'], $aspect->calls);
            $this->assertSame(0, $bean->runs);
        } finally {
            NativeAopDriver::setNativeActive(false);
            NativeAopDriver::clearBypass();
        }
    }

    public function testBodyExceptionPropagatesNatively(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        try {
            [$aspect, $bean] = $this->bootAdvised('boom');

            try {
                $bean->boom();
                $this->assertTrue(false, 'expected body-boom');
            } catch (Throwable $e) {
                $this->assertSame('body-boom', $e->getMessage());
            }
            $this->assertSame(['begin', 'failed:body-boom'], $aspect->calls);
        } finally {
            NativeAopDriver::setNativeActive(false);
            NativeAopDriver::clearBypass();
        }
    }

    public function testIsAdvisedMirrorsRegistration(): void {
        if (!extension_loaded('winter_boot')) {
            $this->assertTrue(true);
            return;
        }
        try {
            $this->bootAdvised('greet');

            $this->assertTrue(winter_boot_is_advised(NativeBean::class, 'greet'));
            $this->assertFalse(winter_boot_is_advised(NativeBean::class, 'plain'));
        } finally {
            NativeAopDriver::setNativeActive(false);
            NativeAopDriver::clearBypass();
        }
    }
}
