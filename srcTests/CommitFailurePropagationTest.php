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
use dev\winterframework\stereotype\aop\PropagatesCommitFailure;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\AttributeList;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;
use winterBootTests\Support\TestCase;

/**
 * Records its calls into a shared log; optionally fails in commit() and/or
 * in commitFailed().
 */
class CommitLogAspect implements WinterAspect {
    public function __construct(
        private string $name,
        private CommitLog $log,
        private bool $failCommit = false,
        private bool $failHandler = false
    ) {
    }

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        $this->log->calls[] = $this->name . ':commit';
        if ($this->failCommit) {
            throw new RuntimeException($this->name . ' commit failed');
        }
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
        $this->log->calls[] = $this->name . ':commitFailed';
        if ($this->failHandler) {
            throw new RuntimeException($this->name . ' handler failed');
        }
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
    }
}

/** A commit failure of this aspect must reach the caller (like a transaction). */
class PropagatingCommitLogAspect extends CommitLogAspect implements PropagatesCommitFailure {
}

class CommitLog {
    /** @var string[] */
    public array $calls = [];
}

class CommitLogGuard implements AopStereoType {
    public function __construct(private WinterAspect $aspect) {
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

class CommitLogTarget {
    public function target(): string {
        return 'ok';
    }
}

/**
 * A transaction that fails to commit must not look like a successful call:
 * failures of PropagatesCommitFailure aspects are rethrown to the caller,
 * after every aspect has committed (locks released, caches updated). Other
 * aspects keep the log-and-swallow commit semantics.
 */
final class CommitFailurePropagationTest extends TestCase {

    /** @param WinterAspect[] $aspects */
    private function finishWith(array $aspects): ?Throwable {
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
        $classRes->setClass(RefKlass::getInstance(CommitLogTarget::class));
        $methRes = new MethodResource();
        $methRes->setMethod(RefMethod::getInstance(new ReflectionMethod(CommitLogTarget::class, 'target')));
        $methRes->setAttributes(AttributeList::ofArray(array_map(fn($a) => new CommitLogGuard($a), $aspects)));

        $registry = new AopInterceptorRegistry($ctxData, $appCtx);
        $registry->register($classRes, $methRes);
        NativeAopDriver::boot($registry, $appCtx);

        try {
            $res = NativeAopDriver::begin(new stdClass(), CommitLogTarget::class, 'target', []);
            NativeAopDriver::finish($res['exCtx'], $res['interceptor'], 'returned', 'ok');
            return null;
        } catch (Throwable $e) {
            return $e;
        } finally {
            NativeAopDriver::clearBypass();
        }
    }

    public function testPropagatingCommitFailureReachesCaller(): void {
        $log = new CommitLog();
        $thrown = $this->finishWith([
            new PropagatingCommitLogAspect('txn', $log, true),
            new CommitLogAspect('lock', $log),
        ]);

        $this->assertTrue($thrown instanceof RuntimeException, 'commit failure must be rethrown');
        $this->assertSame('txn commit failed', $thrown?->getMessage());
        $this->assertSame(['txn:commit', 'txn:commitFailed', 'lock:commit'], $log->calls,
            'remaining aspects still commit before the failure is rethrown');
    }

    public function testOtherCommitFailuresAreStillSwallowed(): void {
        $log = new CommitLog();
        $thrown = $this->finishWith([
            new CommitLogAspect('cache', $log, true),
            new CommitLogAspect('lock', $log),
        ]);

        $this->assertNull($thrown, 'non-transactional commit failures keep log-and-swallow semantics');
        $this->assertSame(['cache:commit', 'cache:commitFailed', 'lock:commit'], $log->calls);
    }

    public function testFailingHandlerDoesNotStopOtherAspectsOrHideFailure(): void {
        $log = new CommitLog();
        $thrown = $this->finishWith([
            new PropagatingCommitLogAspect('txn', $log, true, true),
            new CommitLogAspect('lock', $log),
        ]);

        $this->assertSame('txn commit failed', $thrown?->getMessage(),
            'the commit failure wins over the commitFailed() handler error');
        $this->assertSame(['txn:commit', 'txn:commitFailed', 'lock:commit'], $log->calls);
    }

    public function testFirstPropagatingFailureWins(): void {
        $log = new CommitLog();
        $thrown = $this->finishWith([
            new CommitLogAspect('cache', $log, true),
            new PropagatingCommitLogAspect('txn1', $log, true),
            new PropagatingCommitLogAspect('txn2', $log, true),
        ]);

        $this->assertSame('txn1 commit failed', $thrown?->getMessage());
    }
}
