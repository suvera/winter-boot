<?php
declare(strict_types=1);

namespace dev\winterframework\io\queue;

use dev\winterframework\core\System;

class QueueConfig {

    public function __construct(
        protected string $token,
        protected int $port,
        protected ?string $address = null,
        protected ?string $phpBinary = null,
        protected ?string $diskPath = null
    ) {
        if (!is_int($port) || !$port || $port < 1 || $port > 65535) {
            throw new QueueException('KV Server port must be a number between 1 - 65535');
        }

        if (!$this->address) {
            $this->address = '127.0.0.1';
        }

        if ($this->phpBinary) {
            if (preg_match('/[^a-zA-Z0-9\-_\/]+/', $this->phpBinary)) {
                throw new QueueException('KV Server php binary path has special characters');
            }
        } else {
            $this->phpBinary = System::getPhpBinary();
        }
    }

    public function getPort(): int {
        return $this->port;
    }

    public function getAddress(): string {
        return $this->address;
    }

    public function getPhpBinary(): string {
        return $this->phpBinary;
    }

    public function getToken(): string {
        return $this->token;
    }

}