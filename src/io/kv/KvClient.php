<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\util\log\Wlf4p;
use Swoole\Client;

class KvClient {
    use Wlf4p;

    protected Client $client;

    public function __construct(protected KvConfig $config) {
        $this->client = new Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
        $this->connect();
    }

    protected function connect(): void {
        if (!$this->client->connect($this->config->getAddress(), $this->config->getPort(), -1)) {
            throw new KvException("KV Store Connection failed. Error: {$this->client->errCode}");
        }
    }

    public function get(string $domain, string $key): mixed {
        self::logInfo("Getting data from Shared KV store for $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::GET);
        $req->setKey($key);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function put(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        self::logInfo("Storing to Shared KV store $domain:$key");
        $req = new KvRequest();
        $req->setCommand(KvCommand::PUT);
        $req->setKey($key);
        $req->setData($data);
        $req->setTtl($ttl);
        $req->setDomain($domain);

        $resp = $this->send($req);

        return ($resp->getData() == 'OK');
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
        self::logInfo("Checking key exist in Shared KV store $domain:$key");
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

    public function send(KvRequest $req): KvResponse {
        if (!$this->client->isConnected()) {
            $this->connect();
        }

        //echo "REQ: " . $req . "\n";
        $this->client->send($req . "\n");
        $data = $this->client->recv();
        //echo "RAW: $data\n";

        return KvResponse::jsonUnSerialize(json_decode($data, true));
    }

    public function __destruct() {
        $this->client->close();
    }

}