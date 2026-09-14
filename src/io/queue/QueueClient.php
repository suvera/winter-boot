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
        $data = $this->client->recv($this->config->getTimeout());
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