<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use JsonSerializable;
use Stringable;

class QueueRequest implements JsonSerializable, Stringable {
    const CMD = 0;
    const QUEUE = 1;
    const DATA = 2;
    const TOKEN = 3;

    protected array $json = [
        self::CMD => 0,
        self::QUEUE => '',
        self::DATA => null,
        self::TOKEN => ''
    ];

    public function getCommand(): int {
        return $this->json[self::CMD];
    }

    public function setCommand(int $command): void {
        $this->json[self::CMD] = $command;
    }

    public function getData(): mixed {
        return $this->json[self::DATA];
    }

    public function setData(mixed $data): void {
        $this->json[self::DATA] = $data;
    }

    public function getQueue(): string {
        return $this->json[self::QUEUE];
    }

    public function setQueue(string $domain): void {
        $this->json[self::QUEUE] = $domain;
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