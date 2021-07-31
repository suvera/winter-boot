<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

abstract class MonitoringServerProcess extends ServerWorkerProcess {

    /**
     * @var resource[]
     */
    protected array $streams = [];

    /**
     * @var resource
     */
    protected mixed $proc;

    public function __destruct() {
        if ($this->proc) {
            proc_close($this->proc);
        }
    }

    abstract public function getChildProcessId(): string;

    abstract public function getChildProcessType(): int;

    abstract protected function onProcessStart(): void;

    abstract protected function onProcessError(): void;

    abstract protected function onProcessDead(): void;

    protected function launchAndMonitor(string $cmd, array $linerAgs = []): void {

        $descriptorSpec = [
            ProcessUtil::STDIN => ['pipe', 'r'],
            ProcessUtil::STDOUT => ['pipe', 'w'],
            ProcessUtil::STDERR => ['pipe', 'w']
        ];

        $this->streams = [];

        $process = proc_open($cmd, $descriptorSpec, $this->streams);

        //self::logInfo("Command: $cmd");
        if (is_resource($process)) {
            $this->proc = $process;

            $status = proc_get_status($this->proc);
            if (isset($status['pid'])) {
                $this->wServer->addPid($this->getChildProcessId(), intval($status['pid']), $this->getChildProcessType());
                $childPids = ProcessUtil::getChildPids($status['pid']);

                $i = 0;
                foreach ($childPids as $pid) {
                    if ($pid == $status['pid']) {
                        continue;
                    }
                    $psId = $this->getChildProcessId() . '-' . ($i++);
                    $info = ProcessUtil::getPidInfo($pid);
                    if ($info) {
                        self::logInfo(' #### **** Process started "' . $psId . '" pid:' . $pid
                            . ', threads:' . $info->getThreads()
                            . ' ' . $info->getName());
                    }
                    posix_setpgid($pid, $this->wServer->getServer()->master_pid);
                    $this->wServer->addPid($psId, $pid, $this->getChildProcessType());
                }
            }

            $this->onProcessStart();
        } else {
            self::logError('Could not span process');
            $this->onProcessError();
            return;
        }

        foreach ($linerAgs as $arg) {
            fwrite($this->streams[ProcessUtil::STDIN], $arg . "\n");
        }

        fclose($this->streams[ProcessUtil::STDIN]);

        stream_set_blocking($this->streams[ProcessUtil::STDOUT], false);
        stream_set_blocking($this->streams[ProcessUtil::STDERR], false);

        while (1) {
            $this->poll();
        }
    }

    protected function poll(): void {
        $read = [$this->streams[ProcessUtil::STDOUT], $this->streams[ProcessUtil::STDERR]];
        $write = null;
        $except = null;
        $timeoutUSec = 200000;

        $ret = stream_select($read, $write, $except, 0, $timeoutUSec);

        if ($ret <= 0) {
            return;
        }

        foreach ($read as $stream) {
            if ($stream === $this->streams[ProcessUtil::STDOUT]) {

                $data = $this->readFromStream($stream);
                foreach (explode("\n", $data) as $line) {
                    if ($line) {
                        self::logDebug(' ' . $line);
                    }
                }

            } else if ($stream === $this->streams[ProcessUtil::STDERR]) {

                $data = $this->readFromStream($stream);
                foreach (explode("\n", $data) as $line) {
                    if ($line) {
                        self::logError(' ' . $line);
                    }
                }

            } else {
                self::logError('got unknown stream');
            }
        }

        $currentStatus = proc_get_status($this->proc);

        if (!$currentStatus['running']) {
            self::logError('Error: Process down');
            $this->onProcessDead();
        }
    }

    protected function readFromStream(mixed $stream, int $len = 1024): string {
        $data = '';
        while (($d = fread($stream, $len)) != '') {
            $data .= $d;
        }

        return $data;
    }
}