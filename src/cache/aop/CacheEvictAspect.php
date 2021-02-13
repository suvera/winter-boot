<?php
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\cache\stereotype\CacheEvict;
use dev\winterframework\core\aop\AopResultsFound;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CacheEvictAspect implements WinterAspect {
    const OPERATION = 'CacheEvict';
    use Wlf4p;
    use CacheableTrait;

    public function begin(AopContext $ctx, object $target, array $args): void {
        $this->getCaches($ctx, self::OPERATION, $target);

        /** @var CacheEvict $stereo */
        $stereo = $ctx->getStereoType();
        if (!$stereo->beforeInvocation) {
            return;
        }
        $this->evictCaches($stereo, $ctx, $target, $args);
    }

    public function beginFailed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        if (!($ex instanceof AopResultsFound)) {
            self::logException($ex);
        }
    }

    public function commit(AopContext $ctx, object $target, array $args, mixed $result): void {
        /** @var CacheEvict $stereo */
        $stereo = $ctx->getStereoType();
        if ($stereo->beforeInvocation) {
            return;
        }

        $this->evictCaches($stereo, $ctx, $target, $args);
    }

    public function commitFailed(
        AopContext $ctx,
        object $target,
        array $args,
        mixed $result,
        Throwable $ex
    ): void {
        self::logException($ex);
    }

    public function failed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        self::logException($ex);
    }

    private function evictCaches(CacheEvict $stereo, AopContext $ctx, object $target, array $args): void {
        $caches = $this->getCaches($ctx, self::OPERATION, $target);

        $key = null;
        foreach ($caches as $cache) {
            if ($stereo->allEntries) {
                $cache->clear();
            } else {
                if ($key == null) {
                    $key = $this->generateKey($ctx, $target, $args);
                }
                $cache->evict($key);
            }
        }
    }

}