<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

interface ProcessType {
    const OTHER = 0;

    const MASTER = 1;
    const MANAGER = 2;
    const HTTP_WORKER = 3;
    const TASK_WORKER = 4;
    const ASYNC_WORKER = 5;
    const SCHED_WORKER = 6;

    const KV_MONITOR = 7;
    const QUEUE_MONITOR = 8;

    const KV_SERVER = 9;
    const QUEUE_SERVER = 10;
}