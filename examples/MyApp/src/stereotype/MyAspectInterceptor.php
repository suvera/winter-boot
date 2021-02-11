<?php
declare(strict_types=1);

namespace examples\MyApp\stereotype;


use dev\winterframework\stereotype\aop\AopContext;
use dev\winterframework\stereotype\aop\WinterAspect;
use Throwable;

class MyAspectInterceptor implements WinterAspect {
    
    public function begin(AopContext $ctx, object $target, array $args) {
        echo "MyAspect Begin\n";
    }

    public function beginFailed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        echo "MyAspect Begin Failed\n";
    }

    public function commit(AopContext $ctx, object $target, array $args, mixed $result) {
        echo "MyAspect Commit Done!\n";
    }

    public function commitFailed(AopContext $ctx, object $target, array $args, mixed $result, Throwable $ex) {
        echo "MyAspect Commit Failed\n";
    }

    public function failed(AopContext $ctx, object $target, array $args, Throwable $ex) {
        echo "MyAspect Failed\n";
    }

}