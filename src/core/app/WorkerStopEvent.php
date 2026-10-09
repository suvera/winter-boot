<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

/**
 * Implemented by #[OnWorkerStop] beans. Runs inside every HTTP worker and
 * Swoole task worker process; $workerId is Swoole's worker id.
 */
interface WorkerStopEvent {

    public function onWorkerStop(int $workerId): void;

}
