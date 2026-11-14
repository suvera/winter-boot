<?php

declare(strict_types=1);

namespace winterBootTests\swoole\support;

/**
 * Boots srcTests/fixtures/webapp/WebFixtureApp in a subprocess on a free
 * port with a generated config directory, waits until it accepts
 * connections, and stops it again. The server's output is kept in a log
 * file inside the config directory (see log()).
 */
final class FixtureServer {
    /** @var resource|null */
    private $process = null;
    private int $pid = 0;
    private string $configDir;
    public readonly int $port;

    /**
     * @param string $serverYaml appended under "server:" (indent 4 spaces), e.g. a websocket block
     * @param string $extraYaml appended under "winter:" (indent 4 spaces)
     * @param string $appClass fixture application class (default WebFixtureApp)
     */
    public function __construct(
        string $serverYaml = '',
        string $extraYaml = '',
        int $workers = 1,
        private string $appClass = '',
        string $contextPath = '/'
    ) {
        $this->port = self::freePort();
        $this->configDir = sys_get_temp_dir() . '/wb-fixture-' . getmypid() . '-' . $this->port;
        @mkdir($this->configDir, 0700, true);

        file_put_contents($this->configDir . '/application.yml', <<<YML
server:
    port: {$this->port}
    address: 127.0.0.1
    context-path: {$contextPath}
    swoole:
        worker_num: {$workers}
        log_level: 4
$serverYaml
winter:
    application:
        name: wb-fixture
        id: wb-fixture
        version: 1.0.0
$extraYaml
YML);
        file_put_contents($this->configDir . '/logger.yml', <<<YML
loggers:
    wbFixture:
        handlers: [ console ]
handlers:
    console:
        class: Monolog\Handler\StreamHandler
        level: DEBUG
        stream: php://stdout
YML);
    }

    public static function freePort(): int {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
        if ($sock === false) {
            throw new \RuntimeException("Could not reserve a port: $errStr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int)substr((string)strrchr((string)$name, ':'), 1);
    }

    public function start(float $timeout = 15.0): self {
        $cmd = [PHP_BINARY, __DIR__ . '/boot-webapp.php', '-c', $this->configDir];
        if ($this->appClass !== '') {
            $cmd[] = '--app=' . $this->appClass;
        }
        $log = $this->configDir . '/server.log';
        $this->process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Could not start the fixture server');
        }
        $this->pid = (int)proc_get_status($this->process)['pid'];
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errNo, $errStr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                // Workers may still be starting after the port opens.
                usleep(300000);
                return $this;
            }
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            usleep(100000);
        }
        $log = $this->log();
        $this->stop();
        throw new \RuntimeException("Fixture server did not start:\n" . $log);
    }

    public function pid(): int {
        return $this->pid;
    }

    /** Whether the server process is still alive (safe inside coroutines). */
    public function isRunning(): bool {
        if ($this->pid <= 0 || !posix_kill($this->pid, 0)) {
            return false;
        }
        // An exited child stays a zombie until reaped: count it as stopped.
        $stat = @file_get_contents('/proc/' . $this->pid . '/stat');
        return !is_string($stat) || !preg_match('/^\d+ \(.*\) Z/', $stat);
    }

    /** Safe inside coroutines too (no proc_* calls, which Swoole hooks). */
    public function signal(int $signal): void {
        if ($this->pid > 0 && posix_kill($this->pid, 0)) {
            posix_kill($this->pid, $signal);
        }
    }

    public function log(): string {
        return (string)@file_get_contents($this->configDir . '/server.log');
    }

    public function stop(): void {
        if (!$this->process) {
            return;
        }
        $this->stopProcess();
        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    private function stopProcess(): void {
        $this->signal(SIGTERM);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(50000);
        }
        if (proc_get_status($this->process)['running']) {
            $this->signal(SIGKILL);
        }
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct() {
        $this->stop();
    }

    /**
     * Plain blocking HTTP/1.1 request (outside coroutines).
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function http(string $method, string $path, array $headers = [], string $body = ''): array {
        $sock = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errNo, $errStr, 5);
        if ($sock === false) {
            throw new \RuntimeException("connect failed: $errStr");
        }
        stream_set_timeout($sock, 10);
        $req = "$method $path HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nConnection: close\r\n";
        foreach ($headers as $k => $v) {
            $req .= "$k: $v\r\n";
        }
        if ($body !== '') {
            $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        fwrite($sock, $req . "\r\n" . $body);
        $raw = stream_get_contents($sock);
        fclose($sock);
        return self::parseResponse((string)$raw);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public static function parseResponse(string $raw): array {
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $status = (int)(explode(' ', (string)array_shift($lines))[1] ?? 0);
        $headers = [];
        foreach ($lines as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($k))] = trim($v);
        }
        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            $decoded = '';
            while ($body !== '') {
                $pos = strpos($body, "\r\n");
                if ($pos === false) {
                    break;
                }
                $len = hexdec(trim(explode(';', substr($body, 0, $pos))[0]));
                if ($len === 0) {
                    break;
                }
                $decoded .= substr($body, $pos + 2, (int)$len);
                $body = substr($body, $pos + 2 + (int)$len + 2);
            }
            $body = $decoded;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
