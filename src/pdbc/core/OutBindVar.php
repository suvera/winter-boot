<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

class OutBindVar {

    public function __construct(
        private string|int $name,
        public int $maxLength,
        protected int $type = BindType::STRING
    ) {
    }

    public static function of(
        string|int $name,
        int $maxLength,
        int $type = BindType::STRING
    ): self {
        return new self($name, $maxLength, $type);
    }

    public function getName(): int|string {
        return $this->name;
    }

    public function setName(int|string $name): OutBindVar {
        $this->name = $name;
        return $this;
    }

    public function getMaxLength(): int {
        return $this->maxLength;
    }

    public function setMaxLength(int $maxLength): OutBindVar {
        $this->maxLength = $maxLength;
        return $this;
    }

    public function getType(): int {
        return $this->type;
    }

    public function setType(int $type): OutBindVar {
        $this->type = $type;
        return $this;
    }

}