<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

class BindVar {

    public function __construct(
        private string|int $name,
        public mixed $value,
        protected int $type = BindType::STRING
    ) {
    }

    public static function of(
        string|int $name,
        mixed $value,
        int $type = BindType::STRING
    ): self {
        return new self($name, $value, $type);
    }

    public function getName(): string|int {
        return $this->name;
    }

    public function setName(string $name): BindVar {
        $this->name = $name;
        return $this;
    }

    public function getValue(): mixed {
        return $this->value;
    }

    public function setValue(mixed $value): BindVar {
        $this->value = $value;
        return $this;
    }

    public function getType(): int {
        return $this->type;
    }

    public function setType(int $type): BindVar {
        $this->type = $type;
        return $this;
    }

}