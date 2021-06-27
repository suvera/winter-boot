<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\ServerWorkerProcess;
use dev\winterframework\util\log\Wlf4p;

class KvServerProcess extends ServerWorkerProcess {
    use Wlf4p;

    const STDIN = 0;
    const STDOUT = 1;
    const STDERR = 2;
    /**
     * @var resource[]
     */
    protected array $streams = [];

    /**
     * @var resource
     */
    protected mixed $proc;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        protected KvConfig $config
    ) {
        parent::__construct($wServer, $ctx);

        $connection = @fsockopen(
            $this->config->getAddress(),
            $this->config->getPort(),
            $errno,
            $errStr,
            30
        );

        if (is_resource($connection)) {
            fclose($connection);
            throw new KvException('KV Server port ' . $this->config->getPort() . ' already in use');
        }
    }

    protected function run(): void {

        $descriptorSpec = [
            self::STDIN => ['pipe', 'r'],
            self::STDOUT => ['pipe', 'w'],
            self::STDERR => ['pipe', 'w']
        ];

        $this->streams = [];

        $cmd = $this->config->getPhpBinary() . ' '
            . dirname(dirname(dirname(__DIR__))) . '/bin/kv-server.php';
        $process = proc_open($cmd, $descriptorSpec, $this->streams);

        if (is_resource($process)) {
            self::logInfo('KV Server started on port ' . $this->config->getAddress()
                . ':' . $this->config->getPort());
            $this->proc = $process;
        } else {
            throw new KvException('Could not span KV Service process');
        }

        fwrite($this->streams[self::STDIN], $this->config->getPort() . "\n");
        fwrite($this->streams[self::STDIN], $this->config->getAddress() . "\n");

        fclose($this->streams[self::STDIN]);

        stream_set_blocking($this->streams[self::STDOUT], false);
        stream_set_blocking($this->streams[self::STDERR], false);

        while (1) {
            $this->poll();
        }
    }

    protected function poll(): void {
        $read = [$this->streams[self::STDOUT], $this->streams[self::STDERR]];
        $write = null;
        $except = null;
        $timeoutUSec = 200000;

        $ret = stream_select($read, $write, $except, 0, $timeoutUSec);

        if ($ret <= 0) {
            return;
        }

        foreach ($read as $stream) {
            if ($stream === $this->streams[self::STDOUT]) {

                $data = $this->readFromStream($stream);
                foreach (explode("\n", $data) as $line) {
                    if ($line) {
                        self::logDebug('KV: ' . $line);
                    }
                }

            } else if ($stream === $this->streams[self::STDERR]) {

                $data = $this->readFromStream($stream);
                foreach (explode("\n", $data) as $line) {
                    if ($line) {
                        self::logError('KV: ' . $line);
                    }
                }

            } else {
                self::logError('KV: got unknown stream');
            }
        }

        $currentStatus = proc_get_status($this->proc);

        if (!$currentStatus['running']) {
            self::logError('Error: KV Server down');
            throw new KvException('KV Server down');
        }
    }

    private function readFromStream(mixed $stream, int $len = 1024): string {
        $data = '';
        while (($d = fread($stream, $len)) != '') {
            $data .= $d;
        }

        return $data;
    }

}