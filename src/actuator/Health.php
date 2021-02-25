<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use JsonSerializable;
use Stringable;
use Throwable;

class Health implements Stringable, JsonSerializable {
    private string $status;
    private array $details = [];

    public function __construct(string $status) {
        $this->status = $status;
    }

    public function jsonSerialize(): array {
        $json = [
            'status' => $this->status,
        ];

        if (!empty($this->details)) {
            $json['details'] = $this->details;
        }

        return $json;
    }

    public function __toString(): string {
        return json_encode($this->jsonSerialize());
    }

    public function getStatus(): string {
        return $this->status;
    }

    public static function unknown(): self {
        return new self(Status::UNKNOWN);
    }

    public static function up(): self {
        return new self(Status::UP);
    }

    public static function down(Throwable $ex = null): self {
        $h = new self(Status::DOWN);
        if ($ex) {
            $h->withDetail("error", get_class($ex) . ": " . $ex->getMessage());
        }
        return $h;
    }

    public static function outOfService(): self {
        return new self(Status::OUT_OF_SERVICE);
    }

    public static function status(string $statusCode): self {
        return new self($statusCode);
    }

    public function withDetail(string $name, mixed $value): self {
        $this->details[$name] = $value;
        return $this;
    }
}