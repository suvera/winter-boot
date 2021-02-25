<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use Throwable;

interface AopInterceptor {

    public function getClass(): ClassResource;

    public function getMethod(): MethodResource;

    public function aspectBegin(AopExecutionContext $exCtx): void;

    public function aspectFailed(AopExecutionContext $exCtx, Throwable $e): void;

    public function aspectCommit(AopExecutionContext $exCtx, mixed $result): void;
}