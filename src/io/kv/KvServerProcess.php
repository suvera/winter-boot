<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\MonitoringServerProcess;
use dev\winterframework\io\process\ProcessType;
use dev\winterframework\util\SocketUtil;

class KvServerProcess extends MonitoringServerProcess {

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        protected KvConfig $config
    ) {
        parent::__construct($wServer, $ctx);

        if (SocketUtil::isPortOpened($this->config->getAddress(), $this->config->getPort())) {
            throw new KvException('KV Server port ' . $this->config->getPort() . ' already in use');
        }
    }

    public function getProcessType(): int {
        return ProcessType::KV_MONITOR;
    }

    public function getProcessId(): string {
        return 'kv-monitor';
    }

    public function getChildProcessId(): string {
        return 'kv-server';
    }

    public function getChildProcessType(): int {
        return ProcessType::KV_SERVER;
    }

    protected function onProcessStart(): void {
        self::logInfo('KV Server started on port ' . $this->config->getAddress()
            . ':' . $this->config->getPort());
    }

    protected function onProcessError(): void {
        throw new KvException('Could not span KV Service process');
    }

    protected function onProcessDead(): void {
        throw new KvException('KV Server down');
    }

    protected function run(): void {

        $phar = \Phar::running(false);

        if ($phar) {
            $scriptPath = $phar . ' -s "kv-server"';
        } else {
            $scriptPath = dirname(dirname(dirname(__DIR__))) . '/bin/kv-server.php';
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