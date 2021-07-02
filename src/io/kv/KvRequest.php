<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use JsonSerializable;
use Stringable;

class KvRequest implements JsonSerializable, Stringable {
    const CMD = 0;
    const KEY = 1;
    const TTL = 2;
    const DATA = 3;
    const DOMAIN = 4;
    const TOKEN = 5;

    protected array $json = [
        self::CMD => 0,
        self::KEY => '',
        self::TTL => 0,
        self::DATA => null,
        self::DOMAIN => '',
        self::TOKEN => ''
    ];

    public function getCommand(): int {
        return $this->json[self::CMD];
    }

    public function setCommand(int $command): void {
        $this->json[self::CMD] = $command;
    }

    public function getKey(): string {
        return $this->json[self::KEY];
    }

    public function setKey(string $key): void {
        $this->json[self::KEY] = $key;
    }

    public function getData(): mixed {
        return $this->json[self::DATA];
    }

    public function setData(mixed $data): void {
        $this->json[self::DATA] = $data;
    }

    public function getTtl(): int {
        return $this->json[self::TTL];
    }

    public function setTtl(int $ttl): void {
        $this->json[self::TTL] = $ttl;
    }

    public function getDomain(): string {
        return $this->json[self::DOMAIN];
    }

    public function setDomain(string $domain): void {
        $this->json[self::DOMAIN] = $domain;
    }

    public function getToken(): string {
        return $this->json[self::TOKEN];
    }

    public function setToken(string $token): void {
        $this->json[self::TOKEN] = $token;
    }

    public function jsonSerialize(): array {
        return $this->json;
    }

    public static function jsonUnSerialize(array $json): self {
        $obj = new self();
        foreach ($json as $key => $value) {
            $obj->json[$key] = $value;
        }
        return $obj;
    }

    public function __toString(): string {
        return json_encode($this);
    }


}