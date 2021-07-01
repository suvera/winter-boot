<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\util\log\Wlf4p;
use Swoole\Client;

class QueueClient {
    use Wlf4p;

    protected Client $client;

    public function __construct(protected QueueConfig $config) {
        $this->client = new Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
        $this->connect();
    }

    protected function connect(): void {
        if (!$this->client->connect($this->config->getAddress(), $this->config->getPort(), -1)) {
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

    public function send(QueueRequest $req): QueueResponse {
        if (!$this->client->isConnected()) {
            $this->connect();
        }

        //echo "REQ: " . $req . "\n";
        $this->client->send($req . "\n");
        $data = $this->client->recv();
        //echo "RAW: $data\n";

        return QueueResponse::jsonUnSerialize(json_decode($data, true));
    }

    public function __destruct() {
        $this->client->close();
    }

}