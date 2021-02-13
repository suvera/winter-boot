<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\core\aop\AopResultsFound;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CacheableAspect implements WinterAspect {
    const OPERATION = 'Cacheable';
    use Wlf4p;
    use CacheableTrait;

    public function begin(AopContext $ctx, object $target, array $args): void {
        $caches = $this->getCaches($ctx, self::OPERATION, $target);
        $key = $this->generateKey($ctx, $target, $args);

        //echo "\n" . self:: OPERATION . " - Cache Key: $key\n";
        foreach ($caches as $cache) {
            if ($cache->has($key)) {
                throw new AopResultsFound($cache->get($key)->get());
            }
        }
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
        $caches = $this->getCaches($ctx, self::OPERATION, $target);
        $key = $this->generateKey($ctx, $target, $args);

        foreach ($caches as $cache) {
            $cache->put($key, $result);
        }
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

}