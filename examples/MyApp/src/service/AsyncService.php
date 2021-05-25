<?php
declare(strict_types=1);

namespace examples\MyApp\service;
use dev\winterframework\stereotype\Service;
use dev\winterframework\task\async\stereotype\Async;
use dev\winterframework\util\log\Wlf4p;

#[Service]
class AsyncService {
    use Wlf4p;

    #[Async]
    public function lazyWork(int $id, string $name): void {
        self::logInfo(__METHOD__ . " ($name, $id) has been executed Asynchronously! by PID: " . getmypid());
    }
}