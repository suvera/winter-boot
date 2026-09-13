<?php

declare(strict_types=1);

namespace winterBootTests\Fixtures\ProxySkip;

use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;

#[Service]
class ServiceWithAdvice {

    #[Lockable(name: 'guarded')]
    public function guarded(): string {
        return 'ok';
    }
}
