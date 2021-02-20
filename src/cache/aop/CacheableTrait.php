<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheException;
use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\CacheResolver;
use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\cache\stereotype\CacheEvict;
use dev\winterframework\cache\stereotype\CachePut;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopContextExecute;
use dev\winterframework\type\TypeAssert;

trait CacheableTrait {
    use AopContextExecute;

    /**
     * @param AopContext $ctx
     * @param string $op
     * @param object $target
     * @return Cache[]
     */
    protected function getCaches(AopContext $ctx, string $op, object $target): array {
        $caches = $ctx->getCtxData($target, $op);
        if (!empty($caches)) {
            return $caches;
        }

        /** @var Cacheable|CachePut|CacheEvict $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        if (!empty($stereo->cacheManager) || empty($stereo->cacheResolver)) {
            $cacheManager = $this->getCacheManager($stereo, $appCtx);
            $cacheNames = $stereo->getCacheNames();
        } else {
            $cacheResolver = $this->getCacheResolver($stereo, $appCtx);
            $cacheNames = $cacheResolver->getCacheNames($ctx, $target);
            $cacheManager = $cacheResolver->getCacheManager();
        }

        if (empty($cacheNames)) {
            throw new CacheException(' No cache names could be detected on method "'
                . ReflectionUtil::getFqName($ctx->getMethod()) . '" '
            );
        }

        $caches = [];
        foreach ($cacheNames as $cacheName) {
            $cache = $cacheManager->getCache($cacheName);
            if ($cache == null) {
                throw new CacheException('Could not find Cache with name ' . $cacheName);
            }

            $caches[] = $cache;
        }

        $ctx->setCtxData($target, $op, $caches);
        return $caches;
    }

    protected function getCacheManager(
        Cacheable|CacheEvict|CachePut $stereo,
        ApplicationContext $appCtx
    ): CacheManager {
        $cacheManager = empty($stereo->cacheManager) ? $appCtx->beanByClass(CacheManager::class)
            : $appCtx->beanByName($stereo->cacheManager);
        TypeAssert::typeOf($cacheManager, CacheManager::class);

        return $cacheManager;
    }

    protected function getCacheResolver(
        Cacheable|CacheEvict|CachePut $stereo,
        ApplicationContext $appCtx
    ): CacheResolver {
        $cacheResolver = empty($stereo->cacheResolver) ? $appCtx->beanByClass(CacheResolver::class)
            : $appCtx->beanByName($stereo->cacheResolver);
        TypeAssert::typeOf($cacheResolver, CacheResolver::class);

        return $cacheResolver;
    }

    protected function generateKey(
        AopContext $ctx,
        object $target,
        array $args
    ): string {
        /** @var Cacheable|CacheEvict|CachePut $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        if (!empty($stereo->key)) {
            self::buildNameByContext($stereo->getNameObject(), $ctx, $target, $args);
        }

        /** @var KeyGenerator $keyGenerator */
        $keyGenerator = empty($stereo->keyGenerator) ? $appCtx->beanByClass(KeyGenerator::class)
            : $appCtx->beanByName($stereo->keyGenerator);
        TypeAssert::typeOf($keyGenerator, KeyGenerator::class);

        return $keyGenerator->generate($ctx, $target, $args);
    }
}