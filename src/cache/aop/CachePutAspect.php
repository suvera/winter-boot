<?php
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\ex\AopStopExecution;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CachePutAspect implements WinterAspect {
    const OPERATION = 'CachePut';
    use Wlf4p;
    use CacheableTrait;

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        $this->getCaches($ctx, self::OPERATION, $exCtx);
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
        $caches = $this->getCaches($ctx, self::OPERATION, $exCtx);
        $key = $this->generateKey($ctx, $exCtx);
        self::logInfo(self::OPERATION . ': Cache Commit on KEY: ' . $key, [$result]);

        //echo "\n" . self:: OPERATION . " - Cache Key: $key\n";
        foreach ($caches as $cache) {
            $cache->put($key, $result);
        }
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

}