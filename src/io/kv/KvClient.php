<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\util\log\Wlf4p;
use RuntimeException;
use Swoole\Client;

/**
 * @property-read Client $client
 */
class KvClient implements KvTemplate {
    use Wlf4p;

    protected Client $_client;

    public function __construct(
        protected KvConfig $config
    ) {
    }

    /** @noinspection PhpMixedReturnTypeCanBeReducedInspection */
    public function __get(string $name): mixed {
        if ($name === 'client') {
            if (!isset($this->_client)) {
                $this->_client = new Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
                if (!$this->_client->connect($this->config->getAddress(), $this->config->getPort(), 0.1)) {
                    throw new KvException("KV Store Connection failed. Error: {$this->_client->errCode}");
                }
            }
            return $this->_client;
        }
        throw new RuntimeException('Undefined property: KvClient::$name');
    }

    protected function connect(): void {
        if (!$this->client->connect($this->config->getAddress(), $this->config->getPort(), -1)) {
            throw new KvException("KV Store Connection failed. Error: {$this->client->errCode}");
        }
    }

    public function get(string $domain, string $key): mixed {
        self::logDebug("Getting data from Shared KV store for $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::GET);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function put(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        self::logDebug("Storing to Shared KV store $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::PUT);
        $req->setKey($key);
        $req->setData($data);
        $req->setTtl($ttl);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function putIfNot(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        self::logDebug("Storing to Shared KV store(putIfNot)  $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::PUT_IF_NOT);
        $req->setKey($key);
        $req->setData($data);
        $req->setTtl($ttl);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function getSetIfNot(string $domain, string $key, mixed $data, int $ttl = 0): mixed {
        self::logDebug("Storing to Shared KV store(getSetIfNot)  $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::GETSET_IF_NOT);
        $req->setKey($key);
        $req->setData($data);
        $req->setTtl($ttl);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function del(string $domain, string $key): bool {
        $req = new KvRequest();
        $req->setCommand(KvCommand::DEL);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function has(string $domain, string $key): bool {
        self::logDebug("Checking key exist in Shared KV store $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::HAS_KEY);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
    }

    public function ping(): int {
        $req = new KvRequest();
        $req->setCommand(KvCommand::PING);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function delAll(string $domain): bool {
        $req = new KvRequest();
        $req->setCommand(KvCommand::DEL_ALL);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData() == 'OK';
    }

    protected function send(KvRequest $req): KvResponse {
        $req->setToken($this->config->getToken());
        if (!$this->client->isConnected()) {
            $this->connect();
        }

        //echo "REQ: " . $req . "\n";
        $this->client->send($req . "\n");
        $data = $this->client->recv();
        //echo "RAW: $data\n";
        $json = json_decode($data, true);
        if ($json === false || $json[0] === KvResponse::FAILED) {
            self::logError('KV Command failed ' . $data);
        }

        return KvResponse::jsonUnSerialize($json);
    }

    public function __destruct() {
        $this->client->close();
    }

    public function incr(string $domain, string $key, int|float $incVal = null): int|float {
        $req = new KvRequest();
        $req->setCommand(KvCommand::INCR);
        $req->setKey($key);
        $req->setDomain($domain);
        $req->setData($incVal);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function decr(string $domain, string $key, int|float $decVal = null): int|float {
        $req = new KvRequest();
        $req->setCommand(KvCommand::DECR);
        $req->setKey($key);
        $req->setDomain($domain);
        $req->setData($decVal);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function append(string $domain, string $key, string $append): int {
        $req = new KvRequest();
        $req->setCommand(KvCommand::APPEND);
        $req->setKey($key);
        $req->setDomain($domain);
        $req->setData($append);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function getSet(string $domain, string $key, mixed $value): mixed {
        $req = new KvRequest();
        $req->setCommand(KvCommand::GETSET);
        $req->setKey($key);
        $req->setDomain($domain);
        $req->setData($value);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function strLen(string $domain, string $key): int {
        $req = new KvRequest();
        $req->setCommand(KvCommand::STRLEN);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function keys(string $domain, string $key): array {
        $req = new KvRequest();
        $req->setCommand(KvCommand::KEYS);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function getAll(string $domain): array {
        $req = new KvRequest();
        $req->setCommand(KvCommand::GET_ALL);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function stats(): array {
        $req = new KvRequest();
        $req->setCommand(KvCommand::STATS);

        $resp = $this->send($req);

        return $resp->getData();
    }


}