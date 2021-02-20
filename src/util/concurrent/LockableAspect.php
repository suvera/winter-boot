<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\AopContextExecute;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class LockableAspect implements WinterAspect {
    use Wlf4p;
    use AopContextExecute;

    public function begin(AopContext $ctx, object $target, array $args): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        $lockManager = $this->getLockManger($stereo, $appCtx);

        $name = self::buildNameByContext($stereo->getNameObject(), $ctx, $target, $args);
        self::logInfo('Lock name "' . $stereo->name . '", after parsed "' .  $name .'"');

        $lock = $lockManager->provideLock($name, $stereo->ttlSeconds);
        if (!$lock->tryLock()) {
            throw new LockException('Lock cannot be acquired ');
        }
        $ctx->setCtxData($target, $stereo::class, $lock);
    }

    private function getLockManger(
        Lockable $stereo,
        ApplicationContext $appCtx
    ): LockManager {
        $cacheManager = empty($stereo->cacheManager) ? $appCtx->beanByClass(LockManager::class)
            : $appCtx->beanByName($stereo->cacheManager);
        TypeAssert::typeOf($cacheManager, LockManager::class);

        return $cacheManager;
    }

    public function beginFailed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $ctx->getCtxData($target, $stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
        $ctx->clearCtxData($target, $stereo::class);
    }

    public function commit(
        AopContext $ctx,
        object $target,
        array $args,
        mixed $result
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();

        $lock = $ctx->getCtxData($target, $stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
        $ctx->clearCtxData($target, $stereo::class);
    }

    public function commitFailed(
        AopContext $ctx,
        object $target,
        array $args,
        mixed $result,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $ctx->getCtxData($target, $stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
        $ctx->clearCtxData($target, $stereo::class);
    }

    public function failed(
        AopContext $ctx,
        object $target,
        array $args,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $ctx->getCtxData($target, $stereo::class);
        if (isset($lock)) {
            $lock->unlock();
        }
        $ctx->clearCtxData($target, $stereo::class);
    }
}