<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0002;

use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;
use dev\winterframework\task\async\stereotype\Async;

#[Service]
class ProxyTest001 {

    #[Lockable(name: "ProxtTest001")]
    #[Async]
    public function synchronizedMethod(): mixed {
        return '';
    }
}