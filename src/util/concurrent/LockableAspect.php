<?php
declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class LockableAspect implements WinterAspect {
    use Wlf4p;
    private Lock $lock;

    public function begin(AopContext $ctx, object $target, array $args) {
        /** @var Lockable $stereoType */
        $stereoType = $ctx->getStereoType();
        $this->lock = DefaultLockManager::get()->createLock($stereoType->name, $stereoType->provider);
        if (!$this->lock->tryLock()) {
            throw new LockException('Lock cannot be acquired ');
        }
    }

    public function beginFailed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        self::logException($ex);
        if (isset($this->lock)) {
            $this->lock->unlock();
        }
    }

    public function commit(AopContext $ctx, object $target, array $args, mixed $result) {
        if (isset($this->lock)) {
            $this->lock->unlock();
        }
    }

    public function commitFailed(AopContext $ctx, object $target, array $args, mixed $result, Throwable $ex) {
        self::logException($ex);
        if (isset($this->lock)) {
            $this->lock->unlock();
        }
    }

    public function failed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        self::logException($ex);
        if (isset($this->lock)) {
            $this->lock->unlock();
        }
    }


}