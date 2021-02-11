<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use Throwable;

interface WinterAspect {

    public function begin(AopContext $ctx, object $target, array $args);

    public function beginFailed(AopContext $ctx, object $target, array $args, Throwable $ex);

    public function commit(AopContext $ctx, object $target, array $args, mixed $result);

    public function commitFailed(AopContext $ctx, object $target, array $args, mixed $result, Throwable $ex);

    public function failed(AopContext $ctx, object $target, array $args, Throwable $ex);

}