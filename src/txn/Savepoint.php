<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

class Savepoint {

    public function __construct(
        private string $name,
        private int $id = 0
    ) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function getId(): int {
        return $this->id;
    }

    
}