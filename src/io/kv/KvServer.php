<?php
/** @noinspection DuplicatedCode */
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

    public function __construct(
        protected string $token,
        array $config
    ) {
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

        if ($req->getToken() !== $this->token) {
            $resp->setError('Error: Token does not match');
            $server->send($fd, $resp . "\n");
            return;
        }

        $key = $req->getKey();

        if ($req->getCommand() != KvCommand::PING
            && $req->getCommand() != KvCommand::DEL_ALL
            && $req->getCommand() != KvCommand::GET_ALL
            && $req->getCommand() != KvCommand::KEYS
            && $req->getCommand() != KvCommand::STATS
            && $key == ''
        ) {
            $resp->setError('Empty KEY');
            $server->send($fd, $resp . "\n");
            return;
        }

        $this->gc();
        switch ($req->getCommand()) {
            case KvCommand::PING:
                $resp->setData(time());
                break;

            case KvCommand::PUT:
                $this->execPut($req, $resp);
                break;

            case KvCommand::PUT_IF_NOT:
                $this->execPutIfNot($req, $resp);
                break;

            case KvCommand::INCR:
                $this->execIncrement($req, $resp, true);
                break;

            case KvCommand::DECR:
                $this->execIncrement($req, $resp, false);
                break;

            case KvCommand::GET:
                $this->execGet($req, $resp);
                break;

            case KvCommand::DEL:
                $this->execDel($req, $resp);
                break;

            case KvCommand::HAS_KEY:
                $this->execExists($req, $resp);
                break;

            case KvCommand::DEL_ALL:
                $this->execDelAll($req, $resp);
                break;

            case KvCommand::APPEND:
                $this->execAppend($req, $resp);
                break;

            case KvCommand::GETSET:
                $this->execGetSet($req, $resp);
                break;

            case KvCommand::GETSET_IF_NOT:
                $this->execGetSetIfNot($req, $resp);
                break;

            case KvCommand::STRLEN:
                $this->execStrLen($req, $resp);
                break;

            case KvCommand::KEYS:
                $this->execKeys($req, $resp);
                break;

            case KvCommand::GET_ALL:
                $this->execGetAll($req, $resp);
                break;

            case KvCommand::STATS:
                $this->execStats($req, $resp);
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

    protected function unsetTtl(string $domain, string $key): void {
        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            if (isset($this->ttlStore[$data[1]][$domain][$key])) {
                unset($this->ttlStore[$data[1]][$domain][$key]);
            }
        }
    }

    protected function execPutIfNot(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();
        if (isset($this->store[$domain][$key])) {
            $resp->setData('NOK');
            return;
        }

        $this->execPut($req, $resp);
    }

    protected function execPut(KvRequest $req, KvResponse $resp): void {
        $ttl = $req->getTtl();
        $domain = $req->getDomain();
        $key = $req->getKey();

        if ($ttl < 1) {
            $ttl = PHP_INT_MAX;
            $this->unsetTtl($domain, $key);
        } else {
            $ttl = time() + $ttl;
            $this->ttlStore[$ttl][$domain][$key] = true;
            $this->ttlHeap->insert($ttl);
        }
        $req->setTtl($ttl);

        $this->store[$domain][$key] = [$req->getData(), $ttl];
        $resp->setData('OK');
    }

    protected function execIncrement(KvRequest $req, KvResponse $resp, bool $increase): void {
        $domain = $req->getDomain();
        $key = $req->getKey();
        $incr = $req->getData();

        $incr = is_numeric($incr) ? $incr : 1;
        $incr = $increase ? $incr : -1 * $incr;

        $val = 0;
        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            $val = is_numeric($data[0]) ? $data[0] : 0;
        }
        $req->setData($val + $incr);

        $this->execPut($req, $resp);
        $resp->setData($req->getData());
    }

    protected function execDelAll(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $resp->setData('OK');
        if (isset($this->store[$domain])) {
            foreach ($this->store[$domain] as $key => $data) {
                unset($this->ttlStore[$data[1]][$domain][$key]);
            }
        }
        $this->store[$domain] = [];
    }

    protected function execExists(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

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
    }

    protected function execDel(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        $str = 'NOK';
        if (isset($this->store[$domain][$key])) {
            $str = 'OK';
            $data = $this->store[$domain][$key];
            unset($this->ttlStore[$data[1]][$domain][$key]);
            unset($this->store[$domain][$key]);
        }
        $resp->setData($str);
    }

    protected function execGet(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            if ($data[1] > time()) {
                $resp->setData($data[0]);
            } else {
                unset($this->store[$domain][$key]);
                unset($this->ttlStore[$data[1]][$domain][$key]);
            }
        }
    }

    protected function execAppend(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            if (is_scalar($data[0])) {
                $req->setData($data[0] . $req->getData());
            }
        }
        $this->execPut($req, $resp);
        $resp->setData(strlen($req->getData()));
    }

    protected function execGetSet(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        $old = null;
        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            $old = $data[0];
        }
        $this->execPut($req, $resp);
        $resp->setData($old);
    }

    protected function execStrLen(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        $len = 0;
        if (isset($this->store[$domain][$key])) {
            $data = $this->store[$domain][$key];
            if (is_scalar($data[0])) {
                $len = strlen($data[0]);
            }
        }
        $resp->setData($len);
    }

    protected function execKeys(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();

        $keys = [];
        if (isset($this->store[$domain])) {
            $keys = array_keys($this->store[$domain]);
        }
        $resp->setData($keys);
    }

    protected function execGetAll(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();

        $data = [];
        if (isset($this->store[$domain])) {
            foreach ($this->store[$domain] as $key => $row) {
                $data[$key] = $row[0];
            }
        }
        $resp->setData($data);
    }

    protected function execGetSetIfNot(KvRequest $req, KvResponse $resp): void {
        $domain = $req->getDomain();
        $key = $req->getKey();

        if (isset($this->store[$domain][$key])) {
            $resp->setData($this->store[$domain][$key][0]);
            return;
        }

        $this->execPut($req, $resp);
        $resp->setData(null);
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function execStats(KvRequest $req, KvResponse $resp): void {
        $ret = [
            'totalDomains' => count($this->store),
            'memory' => memory_get_usage()
        ];

        $sum = 0;
        foreach ($this->store as $items) {
            $sum += count($items);
        }

        $ret['totalItems'] = $sum;

        $resp->setData($ret);
    }
}