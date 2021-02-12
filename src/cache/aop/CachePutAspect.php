<?php
declare(strict_types=1);

namespace dev\winterframework\cache\aop;

use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use Throwable;

class CachePutAspect implements WinterAspect {
    public function begin(AopContext $ctx, object $target, array $args) {
        // TODO: Implement begin() method.
    }

    public function beginFailed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        // TODO: Implement beginFailed() method.
    }

    public function commit(AopContext $ctx, object $target, array $args, mixed $result) {
        // TODO: Implement commit() method.
    }

    public function commitFailed(AopContext $ctx, object $target, array $args, mixed $result, Throwable $ex) {
        // TODO: Implement commitFailed() method.
    }

    public function failed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        // TODO: Implement failed() method.
    }

}