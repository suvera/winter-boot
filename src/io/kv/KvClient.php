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
        // SR-008: bounded connect, never infinite.
        if (!$this->client->connect(
            $this->config->getAddress(),
            $this->config->getPort(),
            $this->config->getTimeout()
        )) {
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
        // SR-008: fail fast on send/read instead of blocking forever.
        if ($this->client->send($req . "\n") === false) {
            throw new KvException("KV Store send failed. Error: {$this->client->errCode}");
        }
        $data = $this->recvFrame();
        if ($data === false || $data === '') {
            throw new KvException(
                "KV Store read timed out after {$this->config->getTimeout()}s");
        }
        //echo "RAW: $data\n";
        $json = json_decode($data, true);
        if ($json === false || $json[0] === KvResponse::FAILED) {
            self::logError('KV Command failed ' . $data);
        }

        return KvResponse::jsonUnSerialize($json);
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

    public function incr(string $domain, string $key, int|float|null $incVal = null): int|float {
        $req = new KvRequest();
        $req->setCommand(KvCommand::INCR);
        $req->setKey($key);
        $req->setDomain($domain);
        $req->setData($incVal);

        $resp = $this->send($req);

        return $resp->getData();
    }

    public function decr(string $domain, string $key, int|float|null $decVal = null): int|float {
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