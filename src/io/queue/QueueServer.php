<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use SplQueue;
use Swoole\Server;
use Throwable;

class QueueServer {

    protected array $config = [
        'worker_num' => null,
        'max_request' => null,
        'task_worker_num' => null,
        'reactor_num' => 1,

        'daemonize' => false,
        'backlog' => 256,
    ];
    protected Server $server;

    /**
     * @var SplQueue[]
     */
    protected array $store = [];

    public function __construct(array $config) {
        $this->config = array_merge($this->config, $config);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onConnect(Server $server, int $fd, int $reactorId): void {
        echo "Client [$fd] [$reactorId] connected\n";
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onReceive(Server $server, int $fd, int $reactorId, $data): void {
        $data = trim($data);
        $resp = new QueueResponse();
        try {
            $json = json_decode($data, true);
        } catch (Throwable $e) {
            $resp->setError('Error: ' . $e->getMessage());
            $server->send($fd, $resp . "\n");
            return;
        }

        if ($json === false || !is_array($json)) {
            $resp->setError('Empty Command');
            $server->send($fd, $resp . "\n");
            return;
        }

        try {
            $req = QueueRequest::jsonUnSerialize($json);
        } catch (Throwable $e) {
            $resp->setError('Error: ' . $e->getMessage());
            $server->send($fd, $resp . "\n");
            return;
        }

        $queue = $req->getQueue();

        if ($req->getCommand() != QueueCommand::PING
            && $queue == ''
        ) {
            $resp->setError('Empty Queue name');
            $server->send($fd, $resp . "\n");
            return;
        }

        switch ($req->getCommand()) {
            case QueueCommand::PING:
                $resp->setData(time());
                break;

            case QueueCommand::ENQUEUE:
                if (!isset($this->store[$queue])) {
                    $this->store[$queue] = new SplQueue();
                }

                $this->store[$queue]->enqueue($req->getData());
                $resp->setData('OK');
                break;

            case QueueCommand::DEQUEUE:
                if (isset($this->store[$queue])) {
                    if ($this->store[$queue]->isEmpty()) {
                        $resp->setData(null);
                    } else {
                        $resp->setData($this->store[$queue]->dequeue());
                    }
                } else {
                    $resp->setData(null);
                }
                break;

            case QueueCommand::SIZE:
                if (isset($this->store[$queue])) {
                    $resp->setData($this->store[$queue]->count());
                } else {
                    $resp->setData(0);
                }
                break;

            case QueueCommand::DELETE:
                if (isset($this->store[$queue])) {
                    unset($this->store[$queue]);
                    $resp->setData('OK');
                } else {
                    $resp->setData('NOK');
                }
                break;

            default:
                $resp->setError('Invalid Command');
                break;
        }
        $server->send($fd, $resp . "\n");
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function start(int $port, string $address): void {
        $this->server = new Server($address, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);

        $this->server->set($this->config);

        $this->server->on('connect', [$this, 'onConnect']);
        $this->server->on('receive', [$this, 'onReceive']);

        $this->server->on('ManagerStart', function (Server $server) {
            echo "Manager started\n";
        });

        $this->server->start();
    }
}