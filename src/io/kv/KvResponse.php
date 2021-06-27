<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

use JsonSerializable;
use Stringable;

class KvResponse implements JsonSerializable, Stringable {
    const STATUS = 0;
    const ERROR = 1;
    const DATA = 2;

    const SUCCESS = 1;
    const FAILED = 2;
    const COMMUNICATION_FAILED = 3;

    protected array $json = [
        self::STATUS => self::SUCCESS,
        self::ERROR => '',
        self::DATA => null
    ];

    public function getStatus(): int {
        return $this->json[self::STATUS];
    }

    public function setStatus(int $status): void {
        $this->json[self::STATUS] = $status;
    }

    public function getError(): string {
        return $this->json[self::ERROR];
    }

    public function setError(string $error): void {
        $this->json[self::STATUS] = 1;
        $this->json[self::ERROR] = $error;
    }

    public function getData(): mixed {
        return $this->json[self::DATA];
    }

    public function setData(mixed $data): void {
        $this->json[self::DATA] = $data;
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