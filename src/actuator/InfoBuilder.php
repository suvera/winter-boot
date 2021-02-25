<?php
declare(strict_types=1);

namespace dev\winterframework\actuator;

use JsonSerializable;
use Stringable;

class InfoBuilder implements Stringable, JsonSerializable {
    private array $details = [];

    public function getDetails(): array {
        return $this->details;
    }

    public function jsonSerialize(): array {
        return $this->details;
    }

    public function __toString(): string {
        return json_encode($this->details);
    }

    public function withDetail(string $name, mixed $value): self {
        $this->details[$name] = $value;
        return $this;
    }

    public function hasDetail(string $name): bool {
        return isset($this->details[$name]);
    }
}