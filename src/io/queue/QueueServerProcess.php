<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\MonitoringServerProcess;
use dev\winterframework\io\process\ProcessType;
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

    public function getProcessType(): int {
        return ProcessType::QUEUE_MONITOR;
    }

    public function getProcessId(): string {
        return 'queue-monitor';
    }

    public function getChildProcessId(): string {
        return 'queue-server';
    }

    public function getChildProcessType(): int {
        return ProcessType::QUEUE_SERVER;
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

        $phar = \Phar::running(false);

        if ($phar) {
            $scriptPath = $phar . ' -s "queue-server"';
        } else {
            $scriptPath = dirname(dirname(dirname(__DIR__))) . '/bin/queue-server.php';
        }

        $cmd = $this->config->getPhpBinary() . ' ' . $scriptPath;
        self::logInfo($cmd);

        $lineArgs = [
            $this->config->getPort(),
            $this->config->getAddress(),
            $this->config->getToken(),
            $this->wServer->getServer()->master_pid
        ];

        $this->launchAndMonitor($cmd, $lineArgs);
    }

}