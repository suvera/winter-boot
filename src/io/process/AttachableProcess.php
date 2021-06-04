<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use Swoole\Process;

interface AttachableProcess {

    public function __invoke(Process $me): void;

}