<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\io\LineFrameClient;

/**
 * Coroutine-safe: inside a coroutine each call runs on its own non-blocking
 * connection (see LineFrameClient), so concurrent requests in one worker
 * neither block each other nor read each other's replies.
 */
class KvClient implements KvTemplate {
    use Wlf4p;

    protected LineFrameClient $transport;

    public function __construct(
        protected KvConfig $config
    ) {
        $this->transport = new LineFrameClient(
            $this->config->getAddress(),
            $this->config->getPort(),
            $this->config->getTimeout(),
            'KV Store',
            KvException::class
        );
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
        return self::decodeResponse($this->transport->request((string)$req));
    }

    /**
     * Decode one response frame. Payloads are never logged: they can hold
     * cached values or queued messages.
     */
    public static function decodeResponse(string $data): KvResponse {
        $json = json_decode($data, true);
        if (!is_array($json)) {
            throw new KvException('KV Store returned an undecodable response (' . strlen($data) . ' bytes)');
        }
        if (($json[0] ?? null) === KvResponse::FAILED) {
            self::logError('KV Command failed');
        }
        return KvResponse::jsonUnSerialize($json);
    }

    public function __destruct() {
        $this->transport->close();
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