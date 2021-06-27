<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use Swoole\Server;
use Throwable;

class KvServer {

    protected array $config = [
        /**
         * Following won't work with SWOOLE_BASE
         */
        'worker_num' => null,
        'max_request' => null,
        'task_worker_num' => null,
        'reactor_num' => 1,

        'daemonize' => false,
        'backlog' => 256,
    ];
    protected Server $server;

    protected array $store = [];

    public function __construct(array $config) {
        $this->config = array_merge($this->config, $config);
    }

    public function onConnect(Server $server, int $fd, int $reactorId): void {
        echo "Client [$fd] [$reactorId] connected\n";
    }

    public function onReceive(Server $server, int $fd, int $reactorId, $data): void {
        $data = trim($data);
        $resp = new KvResponse();
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
            $req = KvRequest::jsonUnSerialize($json);
        } catch (Throwable $e) {
            $resp->setError('Error: ' . $e->getMessage());
            $server->send($fd, $resp . "\n");
            return;
        }

        if ($req->getCommand() != KvCommand::PING
            && $req->getCommand() != KvCommand::DEL_ALL
            && $req->getKey() == ''
        ) {
            $resp->setError('Empty KEY');
            $server->send($fd, $resp . "\n");
            return;
        }

        switch ($req->getCommand()) {
            case KvCommand::PING:
                $resp->setData(time());
                break;
            case KvCommand::PUT:
                if ($req->getTtl() < 1) {
                    $req->setTtl(PHP_INT_MAX);
                } else {
                    $req->setTtl(time() + $req->getTtl());
                }
                $this->store[$req->getDomain()][$req->getKey()] = [$req->getData(), $req->getTtl()];
                $resp->setData('OK');
                break;

            case KvCommand::GET:
                if (isset($this->store[$req->getDomain()][$req->getKey()])) {
                    $data = $this->store[$req->getDomain()][$req->getKey()];
                    if ($data[1] > time()) {
                        $resp->setData($data[0]);
                    } else {
                        unset($this->store[$req->getDomain()][$req->getKey()]);
                    }
                }
                break;

            case KvCommand::DEL:
                $str = 'NOK';
                if (isset($this->store[$req->getDomain()][$req->getKey()])) {
                    $str = 'OK';
                    unset($this->store[$req->getDomain()][$req->getKey()]);
                }
                $resp->setData($str);
                break;

            case KvCommand::HAS_KEY:
                $str = 'NOK';
                if (isset($this->store[$req->getDomain()][$req->getKey()])) {
                    $data = $this->store[$req->getDomain()][$req->getKey()];
                    if ($data[1] > time()) {
                        $str = 'OK';
                    } else {
                        unset($this->store[$req->getDomain()][$req->getKey()]);
                    }
                }
                $resp->setData($str);
                break;

            case KvCommand::DEL_ALL:
                $resp->setData('OK');
                $this->store[$req->getDomain()] = [];
                break;

            default:
                $resp->setError('Invalid Command');
                break;
        }
        $server->send($fd, $resp . "\n");
    }

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