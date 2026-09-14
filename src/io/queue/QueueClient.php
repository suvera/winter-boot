<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\util\log\Wlf4p;
use RuntimeException;
use Swoole\Client;

/**
 * @property-read Client $client
 */
class QueueClient implements QueueSharedTemplate {
    use Wlf4p;

    protected Client $_client;

    public function __construct(
        protected QueueConfig $config
    ) {
    }

    /** @noinspection PhpMixedReturnTypeCanBeReducedInspection */
    public function __get(string $name): mixed {
        if ($name === 'client') {
            if (!isset($this->_client)) {
                $this->_client = new Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
                // SR-008: bounded connect, never infinite.
                if (!$this->_client->connect(
                    $this->config->getAddress(),
                    $this->config->getPort(),
                    $this->config->getTimeout()
                )) {
                    throw new QueueException("QUEUE Store Connection failed. Error: {$this->_client->errCode}");
                }
            }
            return $this->_client;
        }
        throw new RuntimeException('Undefined property: QueueClient::$name');
    }

    protected function connect(): void {
        // SR-008: bounded connect, never infinite.
        if (!$this->client->connect(
            $this->config->getAddress(),
            $this->config->getPort(),
            $this->config->getTimeout()
        )) {
            throw new QueueException("QUEUE Store Connection failed. Error: {$this->client->errCode}");
        }
    }

    public function dequeue(string $queue): mixed {
        self::logDebug("Getting data from Shared QUEUE store for $queue");
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::DEQUEUE);
        $req->setQueue($queue);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function enqueue(string $queue, mixed $data): bool {
        self::logDebug("Storing to Shared QUEUE store $queue");
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::ENQUEUE);
        $req->setData($data);
        $req->setQueue($queue);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function delete(string $queue): bool {
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::DELETE);
        $req->setQueue($queue);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function size(string $queue): int {
        self::logDebug("Getting size of the QUEUE store $queue");
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::SIZE);
        $req->setQueue($queue);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function ping(): int {
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::PING);

        $resp = $this->send($req);

        return $resp->getData();
    }

    protected function send(QueueRequest $req): QueueResponse {
        $req->setToken($this->config->getToken());
        if (!$this->client->isConnected()) {
            $this->connect();
        }

        //echo "REQ: " . $req . "\n";
        // SR-008: fail fast on send/read instead of blocking forever.
        if ($this->client->send($req . "\n") === false) {
            throw new QueueException("QUEUE Store send failed. Error: {$this->client->errCode}");
        }
        $data = $this->recvFrame();
        if ($data === false || $data === '') {
            throw new QueueException(
                "QUEUE Store read timed out after {$this->config->getTimeout()}s");
        }
        //echo "RAW: $data\n";
        $json = json_decode($data, true);
        if ($json === false || $json[0] === QueueResponse::FAILED) {
            self::logError('Queue Command failed ' . $data);
        }

        return QueueResponse::jsonUnSerialize($json);
    }

    /**
     * Read one newline-terminated response frame from the server.
     *
     * Swoole\Client::recv() takes a buffer size in bytes, not a timeout, so
     * a single recv() cannot bound the read. Keep reading until the trailing
     * "\n" the server appends, giving up past the configured deadline.
     */
    protected function recvFrame(): string|false {
        $deadline = microtime(true) + $this->config->getTimeout();
        $buffer = '';
        while (true) {
            // EAGAIN while polling is expected; errCode is checked below.
            $chunk = @$this->client->recv(65536);
            if ($chunk === false) {
                // EAGAIN: nothing arrived yet, keep waiting for the deadline.
                if ($this->client->errCode === 11 && microtime(true) < $deadline) {
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

    public function __destruct() {
        if (isset($this->_client)) {
            $this->_client->close();
        }
    }

    public function stats(): array {
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::STATS);

        $resp = $this->send($req);

        return $resp->getData();
    }


}