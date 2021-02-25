<?php
declare(strict_types=1);

namespace examples\MyApp\stereotype;


use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use Throwable;

class MyAspectInterceptor implements WinterAspect {

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        echo "MyAspect Begin\n";
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        echo "MyAspect Begin Failed\n";
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        echo "MyAspect Commit Done!\n";
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
        echo "MyAspect Commit Failed\n";
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        echo "MyAspect Failed\n";
    }

}