<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\cache\impl\SimpleValueWrapper;
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

        foreach ($caches as $cache) {
            if (!$cache->has($key)) {
                continue;
            }
            // has() and get() are two calls: the entry may expire in between.
            $value = $cache->get($key);
            if (!self::isHit($value)) {
                continue;
            }
            self::logDebug(self::OPERATION . ': cache hit in "' . $cache->getName() . '"');
            $exCtx->stopExecution($value->get());
            break;
        }
    }

    /**
     * A hit is any wrapper except the shared miss sentinel the built-in
     * caches return for absent/expired keys.
     */
    public static function isHit(ValueWrapper $value): bool {
        return $value !== SimpleValueWrapper::$NULL_VALUE;
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
        // Never log keys or values: both can carry user data.
        self::logDebug(self::OPERATION . ': cache commit');

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