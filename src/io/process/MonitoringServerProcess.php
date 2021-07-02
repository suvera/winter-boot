<?php
declare(strict_types=1);

namespace dev\winterframework\io\process;

use dev\winterframework\util\log\Wlf4p;

abstract class MonitoringServerProcess extends ServerWorkerProcess {
    use Wlf4p;

    /**
     * @var resource[]
     */
    protected array $streams = [];

    /**
     * @var resource
     */
    protected mixed $proc;

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

        if (is_resource($process)) {
            $this->proc = $process;
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
                        self::logDebug('KV: ' . $line);
                    }
                }

            } else if ($stream === $this->streams[ProcessUtil::STDERR]) {

                $data = $this->readFromStream($stream);
                foreach (explode("\n", $data) as $line) {
                    if ($line) {
                        self::logError('KV: ' . $line);
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