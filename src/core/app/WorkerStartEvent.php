<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

/**
 * Implemented by #[OnWorkerStart] beans. Runs inside every HTTP worker and
 * Swoole task worker process; $workerId is Swoole's worker id.
 */
interface WorkerStartEvent {

    public function onWorkerStart(int $workerId): void;

}
