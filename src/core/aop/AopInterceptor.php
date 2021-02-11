<?php
declare(strict_types=1);

namespace dev\winterframework\core\aop;

use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\MethodResource;
use Throwable;

interface AopInterceptor {

    public function getClass(): ClassResource;

    public function getMethod(): MethodResource;

    public function aspectBegin(object $obj, array $args): void;

    public function aspectFailed(object $obj, array $args, Throwable $e): void;

    public function aspectCommit(object $obj, array $args, mixed $result): void;
}