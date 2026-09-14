<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\aop\WinterAopInterceptor;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResourceScanner;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\Psr4Namespaces;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopStereoType;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\AttributeList;
use ReflectionMethod;
use stdClass;
use Throwable;
use winterBootTests\Fixtures\ProxySkip\ControllerWithAdvice;
use winterBootTests\Support\TestCase;

class RecordingAspect implements WinterAspect {
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

class RecordingGuard implements AopStereoType {
    public function __construct(private RecordingAspect $aspect) {
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

class RecordingTarget {
    public function target(): string {
        return 'ok';
    }
}

/**
 * Controller beans carry no proxy, so the dispatcher drives their AOP
 * attributes through the registered interceptor itself: begin() before
 * the body, commit()/failed() around its outcome, stopExecution()
 * short-circuiting the body.
 */
final class DispatcherAopTest extends TestCase {

    /** @return array{0: ApplicationContextData, 1: WinterApplicationContextBuilder} */
    private function newParts(): array {
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
        return [new ApplicationContextData(), $appCtx];
    }

    private function newInterceptor(RecordingAspect $aspect): WinterAopInterceptor {
        [$ctxData, $appCtx] = $this->newParts();
        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance(new ReflectionMethod(RecordingTarget::class, 'target')));
        $methRes->setAttributes(AttributeList::ofArray([new RecordingGuard($aspect)]));
        return new WinterAopInterceptor(new ClassResource(), $methRes, $ctxData, $appCtx);
    }

    public function testRegistryHoldsControllerAopMethods(): void {
        [$ctxData, $appCtx] = $this->newParts();
        $scanner = ClassResourceScanner::getDefaultScanner();
        $ns = Psr4Namespaces::ofArrayItems([
            ['winterBootTests\\Fixtures\\ProxySkip', __DIR__ . '/fixtures/proxy-skip'],
        ]);
        $found = [];
        foreach ($scanner->scan($ns, $scanner->getDefaultStereoTypes(), true, []) as $cls) {
            $found[$cls->getClass()->getName()] = $cls;
        }

        $registry = new AopInterceptorRegistry($ctxData, $appCtx);
        $class = $found[ControllerWithAdvice::class];
        foreach ($class->getMethods() as $method) {
            if ($method->getMethod()->getShortName() === 'guarded') {
                $registry->register($class, $method);
            }
        }

        $this->assertTrue($registry->has(ControllerWithAdvice::class, 'guarded'));
        $this->assertFalse($registry->has(ControllerWithAdvice::class, 'missing'));
        $this->assertFalse($registry->has('NoSuchClass', 'guarded'));
    }

    public function testStopExecutionShortCircuits(): void {
        $aspect = new RecordingAspect(true);
        $interceptor = $this->newInterceptor($aspect);
        $exCtx = new AopExecutionContext(new stdClass(), []);

        $interceptor->aspectBegin($exCtx);
        $exCtx->setBeginDone();

        $this->assertTrue($exCtx->isStopExecution());
        $this->assertSame('STOPPED', $exCtx->getResult());
        // Stopping notifies beginFailed() with AopStopExecution by design.
        $this->assertSame(['begin', 'beginFailed'], $aspect->calls);
    }

    public function testCommitRunsOnSuccess(): void {
        $aspect = new RecordingAspect(false);
        $interceptor = $this->newInterceptor($aspect);
        $exCtx = new AopExecutionContext(new stdClass(), []);

        $interceptor->aspectBegin($exCtx);
        $exCtx->setBeginDone();
        $this->assertFalse($exCtx->isStopExecution());

        $exCtx->setSuccess();
        $exCtx->setResult('ok');
        $interceptor->aspectCommit($exCtx, 'ok');

        $this->assertSame(['begin', "commit:'ok'"], $aspect->calls);
    }

    public function testFailedRunsOnError(): void {
        $aspect = new RecordingAspect(false);
        $interceptor = $this->newInterceptor($aspect);
        $exCtx = new AopExecutionContext(new stdClass(), []);
        $ex = new \RuntimeException('boom');

        $interceptor->aspectBegin($exCtx);
        $exCtx->setException($ex);
        $exCtx->setFailed();
        $interceptor->aspectFailed($exCtx, $ex);

        $this->assertSame(['begin', 'failed:boom'], $aspect->calls);
    }
}
