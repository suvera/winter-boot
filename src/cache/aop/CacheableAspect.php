<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\cache\CacheException;
use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\core\aop\AopResultsFound;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class CacheableAspect implements WinterAspect {
    use Wlf4p;

    public function begin(AopContext $ctx, object $target, array $args) {
        /** @var Cacheable $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        /** @var CacheManager $cacheManager */
        $cacheManager = empty($stereo->cacheManager) ? $appCtx->beanByClass(CacheManager::class)
            : $appCtx->beanByName($stereo->cacheManager);
        TypeAssert::typeOf($cacheManager, CacheManager::class);

        /** @var KeyGenerator $keyGenerator */
        $keyGenerator = empty($stereo->keyGenerator) ? $appCtx->beanByClass(KeyGenerator::class)
            : $appCtx->beanByName($stereo->keyGenerator);
        TypeAssert::typeOf($keyGenerator, KeyGenerator::class);

        $key = $keyGenerator->generate($ctx, $target, $args);
        foreach ($stereo->getCacheNames() as $cacheName) {
            $cache = $cacheManager->getCache($cacheName);
            if ($cache == null) {
                throw new CacheException('Could not find Cache with name ' . $cacheName);
            }

            if ($cache->has($key)) {
                throw new AopResultsFound($cache->get($key)->get());
            }
        }
    }

    public function beginFailed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        if (!($ex instanceof AopResultsFound)) {
            self::logException($ex);
        }
    }

    public function commit(AopContext $ctx, object $target, array $args, mixed $result) {
        /** @var Cacheable $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        /** @var CacheManager $cacheManager */
        $cacheManager = empty($stereo->cacheManager) ? $appCtx->beanByClass(CacheManager::class)
            : $appCtx->beanByName($stereo->cacheManager);
        TypeAssert::typeOf($cacheManager, CacheManager::class);

        /** @var KeyGenerator $keyGenerator */
        $keyGenerator = empty($stereo->keyGenerator) ? $appCtx->beanByClass(KeyGenerator::class)
            : $appCtx->beanByName($stereo->keyGenerator);
        TypeAssert::typeOf($keyGenerator, KeyGenerator::class);

        $key = $keyGenerator->generate($ctx, $target, $args);
        foreach ($stereo->getCacheNames() as $cacheName) {
            $cache = $cacheManager->getCache($cacheName);
            if ($cache == null) {
                throw new CacheException('Could not find Cache with name ' . $cacheName);
            }
            $cache->put($key, $result);
        }
    }

    public function commitFailed(AopContext $ctx, object $target, array $args, mixed $result, Throwable $ex) {
        self::logException($ex);
    }

    public function failed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        self::logException($ex);
    }

}