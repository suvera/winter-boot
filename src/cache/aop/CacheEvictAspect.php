<?php
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\cache\stereotype\CacheEvict;
use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\ex\AopStopExecution;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CacheEvictAspect implements WinterAspect {
    const OPERATION = 'CacheEvict';
    use Wlf4p;
    use CacheableTrait;

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        $this->getCaches($ctx, self::OPERATION, $exCtx);

        /** @var CacheEvict $stereo */
        $stereo = $ctx->getStereoType();
        if (!$stereo->beforeInvocation) {
            return;
        }
        $this->evictCaches($stereo, $ctx, $exCtx);
    }

    public function beginFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        if (!($ex instanceof AopStopExecution)) {
            self::logException($ex);
        }
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        /** @var CacheEvict $stereo */
        $stereo = $ctx->getStereoType();
        if ($stereo->beforeInvocation) {
            return;
        }

        $this->evictCaches($stereo, $ctx, $exCtx);
    }

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void {
        self::logException($ex);
    }

    public function failed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        self::logException($ex);
    }

    private function evictCaches(CacheEvict $stereo, AopContext $ctx, AopExecutionContext $exCtx): void {
        $caches = $this->getCaches($ctx, self::OPERATION, $exCtx);

        $key = null;
        foreach ($caches as $cache) {
            if ($stereo->allEntries) {
                $cache->clear();
            } else {
                if ($key == null) {
                    $key = $this->generateKey($ctx, $exCtx);
                }
                $cache->evict($key);
            }
        }
    }

}