<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\type\IntegerMinHeap;
use Swoole\Server;
use Throwable;

class KvServer {

    protected array $config = [
        'worker_num' => null,
        'max_request' => null,
        'task_worker_num' => null,
        'reactor_num' => 1,

        'daemonize' => false,
        'backlog' => 256,
    ];
    protected Server $server;

    protected array $store = [];
    protected array $ttlStore = [];
    protected IntegerMinHeap $ttlHeap;
    protected int $lastGc = 0;
    protected int $gcCycleTime = 30;

    public function __construct(array $config) {
        $this->config = array_merge($this->config, $config);
        $this->ttlHeap = new IntegerMinHeap();
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onConnect(Server $server, int $fd, int $reactorId): void {
        echo "Client [$fd] [$reactorId] connected\n";
    }

    /** @noinspection PhpUnusedParameterInspection */
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

        $key = $req->getKey();

        if ($req->getCommand() != KvCommand::PING
            && $req->getCommand() != KvCommand::DEL_ALL
            && $key == ''
        ) {
            $resp->setError('Empty KEY');
            $server->send($fd, $resp . "\n");
            return;
        }

        $domain = $req->getDomain();

        switch ($req->getCommand()) {
            case KvCommand::PING:
                $resp->setData(time());
                break;

            case KvCommand::PUT:
                $this->gc();
                $ttl = $req->getTtl();
                
                if ($ttl < 1) {
                    $ttl = PHP_INT_MAX;
                    $req->setTtl($ttl);
                    if (isset($this->store[$domain][$key])) {
                        $data = $this->store[$domain][$key];
                        if (isset($this->ttlStore[$data[1]][$domain][$key])) {
                            unset($this->ttlStore[$data[1]][$domain][$key]);
                        }
                    }
                } else {
                    $ttl = time() + $ttl;
                    $req->setTtl($ttl);
                    $this->ttlStore[$ttl][$domain][$key] = true;
                    $this->ttlHeap->insert($ttl);
                }
                $this->store[$domain][$key] = [$req->getData(), $ttl];
                $resp->setData('OK');
                break;

            case KvCommand::GET:
                $this->gc();
                if (isset($this->store[$domain][$key])) {
                    $data = $this->store[$domain][$key];
                    if ($data[1] > time()) {
                        $resp->setData($data[0]);
                    } else {
                        unset($this->store[$domain][$key]);
                        unset($this->ttlStore[$data[1]][$domain][$key]);
                    }
                }
                break;

            case KvCommand::DEL:
                $str = 'NOK';
                if (isset($this->store[$domain][$key])) {
                    $str = 'OK';
                    $data = $this->store[$domain][$key];
                    unset($this->ttlStore[$data[1]][$domain][$key]);
                    unset($this->store[$domain][$key]);
                }
                $resp->setData($str);
                break;

            case KvCommand::HAS_KEY:
                $this->gc();
                $str = 'NOK';
                if (isset($this->store[$domain][$key])) {
                    $data = $this->store[$domain][$key];
                    if ($data[1] > time()) {
                        $str = 'OK';
                    } else {
                        unset($this->store[$domain][$key]);
                        unset($this->ttlStore[$data[1]][$domain][$key]);
                    }
                }
                $resp->setData($str);
                break;

            case KvCommand::DEL_ALL:
                $resp->setData('OK');
                if (isset($this->store[$domain])) {
                    foreach ($this->store[$domain] as $key => $data) {
                        unset($this->ttlStore[$data[1]][$domain][$key]);
                    }
                }
                $this->store[$domain] = [];
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

        $this->lastGc = time();
        $this->server->start();
    }

    public function gc(): void {
        if (($this->lastGc + $this->gcCycleTime) > time()) {
            return;
        }
        $this->lastGc = time();
        $this->doGc();
    }

    public function doGc(): void {
        if ($this->ttlHeap->isEmpty()) {
            return;
        }
        $ts = time();

        while (!$this->ttlHeap->isEmpty()) {
            $top = $this->ttlHeap->top();

            if ($top > $ts) {
                break;
            }
            $this->ttlHeap->extract();

            if (!isset($this->ttlStore[$top])) {
                continue;
            }

            foreach ($this->ttlStore[$top] as $domain => $keys) {
                foreach ($keys as $key => $flag) {
                    unset($this->store[$domain][$key]);
                    unset($this->ttlStore[$top][$domain][$key]);
                }
            }

            if (empty($this->ttlStore[$top])) {
                unset($this->ttlStore[$top]);
            }
        }
    }
}