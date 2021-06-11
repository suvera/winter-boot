<?php
declare(strict_types=1);

namespace test\winterframework\ppa\classes;

use DateTime;
use dev\winterframework\ppa\PpaEntity;
use dev\winterframework\ppa\PpaEntityTrait;
use dev\winterframework\stereotype\ppa\Column;
use dev\winterframework\stereotype\ppa\Table;

#[Table(name: "ACCOUNT")]
class TestPpaAccount01 implements PpaEntity {
    use PpaEntityTrait;

    #[Column(name: "ACCOUNT_ID", id: true)]
    private int $id;

    #[Column(name: "ACCOUNT_NAME", length: 64)]
    private string $name;

    #[Column(name: "CREATED_ON")]
    private DateTime $created;

    #[Column(name: "BALANCE")]
    private float $balance;

    public function getId(): int {
        return $this->id;
    }

    public function setId(int $id): TestPpaAccount01 {
        $this->id = $id;
        return $this;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): TestPpaAccount01 {
        $this->name = $name;
        return $this;
    }

    public function getCreated(): DateTime {
        return $this->created;
    }

    public function setCreated(DateTime $created): TestPpaAccount01 {
        $this->created = $created;
        return $this;
    }

    public function getBalance(): float {
        return $this->balance;
    }

    public function setBalance(float $balance): TestPpaAccount01 {
        $this->balance = $balance;
        return $this;
    }

}