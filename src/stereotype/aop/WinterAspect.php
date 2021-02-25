<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\core\aop\AopExecutionContext;
use Throwable;

interface WinterAspect {

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void;

    public function beginFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void;

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void;

    public function commitFailed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        mixed $result,
        Throwable $ex
    ): void;

    public function failed(
        AopContext $ctx,
        AopExecutionContext $exCtx,
        Throwable $ex
    ): void;

}