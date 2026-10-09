<?php
declare(strict_types=1);

namespace dev\winterframework\io;

use RuntimeException;
use Swoole\Client;
use Swoole\Coroutine;
use Swoole\Coroutine\Client as CoroutineClient;
use Throwable;

/**
 * Request/response transport for the node-local KV and queue stores: one
 * newline-terminated frame out, one back.
 *
 * Inside a coroutine every exchange runs on a Swoole\Coroutine\Client, which
 * yields instead of blocking the worker. A connection belongs to one
 * coroutine for the whole exchange and goes back to a small idle list only
 * after a clean, complete response; any failure closes it, so a half-read
 * frame never reaches the next caller. Coroutines never touch the blocking
 * process client that serves callers outside coroutines (boot, plain CLI).
 */
final class LineFrameClient {
    /** Idle coroutine connections kept per process for reuse. */
    public const MAX_IDLE = 16;

    /** Largest response frame accepted (getAll / keys can be large). */
    public const MAX_RESPONSE = 64 * 1024 * 1024;

    private ?Client $processClient = null;

    /** @var CoroutineClient[] */
    private array $idle = [];

    /**
     * @param string $label prefix for error messages, e.g. "KV Store"
     * @param class-string<RuntimeException> $exceptionClass thrown on I/O failure
     */
    public function __construct(
        private string $address,
        private int $port,
        private float $timeout,
        private string $label,
        private string $exceptionClass = RuntimeException::class
    ) {
    }

    /**
     * Send one frame and return the response line without its "\n".
     */
    public function request(string $frame): string {
        if (self::inCoroutine()) {
            return $this->coroutineRequest($frame);
        }
        return $this->processRequest($frame);
    }

    public function getIdleCount(): int {
        return count($this->idle);
    }

    public function close(): void {
        foreach ($this->idle as $conn) {
            $conn->close();
        }
        $this->idle = [];
        if ($this->processClient !== null) {
            $this->processClient->close();
            $this->processClient = null;
        }
    }

    private static function inCoroutine(): bool {
        return extension_loaded('swoole') && Coroutine::getCid() > 0;
    }

    private function fail(string $message): RuntimeException {
        $cls = $this->exceptionClass;
        return new $cls($this->label . ' ' . $message);
    }

    private function coroutineRequest(string $frame): string {
        $conn = array_pop($this->idle);
        if ($conn !== null && !$conn->isConnected()) {
            $conn->close();
            $conn = null;
        }
        if ($conn !== null) {
            // An idle connection may have been closed by the server (store
            // restart). A peer close before any reply is retried once on a
            // fresh connection; a timeout is not, the store may have acted.
            $data = $this->exchange($conn, $frame, true);
            if ($data !== null) {
                return $data;
            }
        }
        return $this->exchange($this->openCoroutineClient(), $frame, false);
    }

    /**
     * @return string|null null only when $reused and the peer had closed
     */
    private function exchange(CoroutineClient $conn, string $frame, bool $reused): ?string {
        try {
            if ($conn->send($frame . "\n") === false) {
                if ($reused) {
                    $conn->close();
                    return null;
                }
                throw $this->fail("send failed. Error: {$conn->errCode}");
            }
            $data = $conn->recv($this->timeout);
            if ($data === '' && $reused) {
                $conn->close();
                return null;
            }
            if ($data === false || $data === '') {
                throw $this->fail("read timed out after {$this->timeout}s");
            }
        } catch (Throwable $e) {
            $conn->close();
            throw $e;
        }

        $pos = strpos($data, "\n");
        $line = $pos === false ? $data : substr($data, 0, $pos);
        // Bytes past the frame would be read as the next caller's reply.
        if ($pos === strlen($data) - 1 && count($this->idle) < self::MAX_IDLE) {
            $this->idle[] = $conn;
        } else {
            $conn->close();
        }
        return $line;
    }

    private function openCoroutineClient(): CoroutineClient {
        $conn = new CoroutineClient(SWOOLE_SOCK_TCP);
        $conn->set([
            'open_eof_check' => true,
            'package_eof' => "\n",
            'package_max_length' => self::MAX_RESPONSE,
        ]);
        // SR-008: bounded connect, never infinite.
        if (!$conn->connect($this->address, $this->port, $this->timeout)) {
            $err = $conn->errCode;
            $conn->close();
            throw $this->fail("Connection failed. Error: $err");
        }
        return $conn;
    }

    private function processRequest(string $frame): string {
        if ($this->processClient === null || !$this->processClient->isConnected()) {
            $client = $this->processClient ?? new Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
            // SR-008: bounded connect, never infinite.
            if (!$client->connect($this->address, $this->port, $this->timeout)) {
                $this->processClient = null;
                throw $this->fail("Connection failed. Error: {$client->errCode}");
            }
            $this->processClient = $client;
        }

        // SR-008: fail fast on send/read instead of blocking forever.
        if ($this->processClient->send($frame . "\n") === false) {
            $err = $this->processClient->errCode;
            $this->dropProcessClient();
            throw $this->fail("send failed. Error: $err");
        }
        $data = $this->recvFrame($this->processClient);
        if ($data === false || $data === '') {
            // A late reply would otherwise answer the next request.
            $this->dropProcessClient();
            throw $this->fail("read timed out after {$this->timeout}s");
        }
        return $data;
    }

    private function dropProcessClient(): void {
        if ($this->processClient !== null) {
            $this->processClient->close();
            $this->processClient = null;
        }
    }

    /**
     * Read one newline-terminated response frame from the server.
     *
     * Swoole\Client::recv() takes a buffer size in bytes, not a timeout, so
     * a single recv() cannot bound the read. Keep reading until the trailing
     * "\n" the server appends, giving up past the configured deadline.
     */
    private function recvFrame(Client $client): string|false {
        $deadline = microtime(true) + $this->timeout;
        $buffer = '';
        while (true) {
            // EAGAIN while polling is expected; errCode is checked below.
            $chunk = @$client->recv(65536);
            if ($chunk === false) {
                // EAGAIN: nothing arrived yet, keep waiting for the deadline.
                if ($client->errCode === 11 && microtime(true) < $deadline) {
                    usleep(10000);
                    continue;
                }
                return false;
            }
            if ($chunk === '') {
                return false;
            }
            $buffer .= $chunk;
            $pos = strpos($buffer, "\n");
            if ($pos !== false) {
                return substr($buffer, 0, $pos);
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
        }
    }
}
