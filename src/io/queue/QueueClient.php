<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\io\LineFrameClient;

/**
 * Coroutine-safe: inside a coroutine each call runs on its own non-blocking
 * connection (see LineFrameClient), so concurrent requests in one worker
 * neither block each other nor read each other's replies.
 */
class QueueClient implements QueueSharedTemplate {
    use Wlf4p;

    protected LineFrameClient $transport;

    public function __construct(
        protected QueueConfig $config
    ) {
        $this->transport = new LineFrameClient(
            $this->config->getAddress(),
            $this->config->getPort(),
            $this->config->getTimeout(),
            'QUEUE Store',
            QueueException::class
        );
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
        return self::decodeResponse($this->transport->request((string)$req));
    }

    /**
     * Decode one response frame. Payloads are never logged: they can hold
     * cached values or queued messages.
     */
    public static function decodeResponse(string $data): QueueResponse {
        $json = json_decode($data, true);
        if (!is_array($json)) {
            throw new QueueException('Queue Store returned an undecodable response (' . strlen($data) . ' bytes)');
        }
        if (($json[0] ?? null) === QueueResponse::FAILED) {
            self::logError('Queue Command failed');
        }
        return QueueResponse::jsonUnSerialize($json);
    }

    public function __destruct() {
        $this->transport->close();
    }

    public function stats(): array {
        $req = new QueueRequest();
        $req->setCommand(QueueCommand::STATS);

        $resp = $this->send($req);

        return $resp->getData();
    }


}