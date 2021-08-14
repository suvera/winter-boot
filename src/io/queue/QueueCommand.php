<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

class QueueCommand {
    const ENQUEUE = 1;
    const DEQUEUE = 2;
    const SIZE = 3;
    const DELETE = 4;

    const STATS = 98;
    const PING = 99;
}