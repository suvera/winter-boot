<?php
declare(strict_types=1);

namespace test\winterframework\core\aop\classes;

use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;

#[Service]
class A001Class {

    #[Lockable(name: __METHOD__)]
    public function syncMethod(int $seconds): void {
        echo "\nSleeping for $seconds seconds ...\n";
        sleep($seconds);
        echo "Sync Method Executed\n";
    }
}