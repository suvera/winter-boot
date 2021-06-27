<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\ex\AopStopExecution;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CacheableAspect implements WinterAspect {
    const OPERATION = 'Cacheable';
    use Wlf4p;
    use CacheableTrait;

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        $caches = $this->getCaches($ctx, self::OPERATION, $exCtx);
        $key = $this->generateKey($ctx, $exCtx);
        self::logInfo(self::OPERATION . ': Cache checking for KEY: ' . $key);

        foreach ($caches as $cache) {
            if ($cache->has($key)) {
                self::logInfo(self::OPERATION . ': cache value found in the "'
                    . $cache->getName()
                    . '", for the KEY: ' . $key);
                $exCtx->stopExecution($cache->get($key)->get());
                break;
            } else {
                self::logInfo(self::OPERATION . ': cache value *NOT* found in the "'
                    . $cache->getName()
                    . '", for the KEY: ' . $key);
            }
        }
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