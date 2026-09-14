<?php

declare(strict_types=1);

namespace winterBootTests\Fixtures\ProxySkip;

use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;

#[RestController]
class ControllerWithAdvice {

    #[GetMapping(path: 'guarded')]
    #[Lockable(name: 'guarded')]
    public function guarded(): string {
        return 'ok';
    }
}
