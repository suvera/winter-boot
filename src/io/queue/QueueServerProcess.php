<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\MonitoringServerProcess;
use dev\winterframework\util\SocketUtil;

class QueueServerProcess extends MonitoringServerProcess {

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        protected QueueConfig $config
    ) {
        parent::__construct($wServer, $ctx);
        
        if (SocketUtil::isPortOpened($this->config->getAddress(), $this->config->getPort())) {
            throw new QueueException('QUEUE Server port ' . $this->config->getPort() . ' already in use');
        }
    }

    protected function onProcessStart(): void {
        self::logInfo('QUEUE Server started on port ' . $this->config->getAddress()
            . ':' . $this->config->getPort());
    }

    protected function onProcessError(): void {
        throw new QueueException('Could not span QUEUE Service process');
    }

    protected function onProcessDead(): void {
        throw new QueueException('QUEUE Server down');
    }

    protected function run(): void {

        $cmd = $this->config->getPhpBinary() . ' '
            . dirname(dirname(dirname(__DIR__))) . '/bin/queue-server.php';

        $lineArgs = [
            $this->config->getPort(),
            $this->config->getAddress()
        ];

        $this->launchAndMonitor($cmd, $lineArgs);
    }

}