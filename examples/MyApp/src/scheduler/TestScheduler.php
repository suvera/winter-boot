<?php
declare(strict_types=1);

namespace examples\MyApp\scheduler;

use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\log\Wlf4p;

#[Component]
class TestScheduler {
    use Wlf4p;

    #[Scheduled(fixedDelay: 20, initialDelay: 10)]
    public function doSomethingOnInterval() {
        self::logInfo('I did generated a unique Id on every 20 seconds ' . uniqid());
    }
}