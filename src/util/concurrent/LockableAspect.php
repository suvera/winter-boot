<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\core\aop\AopExecutionContext;
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

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        $appCtx = $ctx->getApplicationContext();

        $lockManager = $this->getLockManger($stereo, $appCtx);

        $name = self::buildNameByContext(
            $stereo->getNameObject(),
            $ctx,
            $exCtx->getObject(),
            $exCtx->getArguments()
        );
        self::logInfo('Lock name "' . $stereo->name . '", after parsed "' . $name . '"');

        $lock = $lockManager->provideLock($name, $stereo->ttlSeconds);
        if (!$lock->tryLock()) {
            throw new LockException('Lock cannot be acquired ');
        }
        $exCtx->setVariable($stereo::class, $lock);
    }

    private function getLockManger(
        Lockable $stereo,
        ApplicationContext $appCtx
    ): LockManager {
        $lockManager = empty($stereo->lockManager) ? $appCtx->beanByClass(LockManager::class)
            : $appCtx->beanByName($stereo->lockManager);

        TypeAssert::typeOf($lockManager, LockManager::class);

        return $lockManager;
    }

    public function beginFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $exCtx->getVariable($stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
    }

    public function commit(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();

        $lock = $exCtx->getVariable($stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
    }

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $exCtx->getVariable($stereo::class);
        if (!empty($lock)) {
            $lock->unlock();
        }
    }

    public function failed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void {
        /** @var Lockable $stereo */
        $stereo = $ctx->getStereoType();
        self::logException($ex);

        $lock = $exCtx->getVariable($stereo::class);
        if (isset($lock)) {
            $lock->unlock();
        }
    }
}