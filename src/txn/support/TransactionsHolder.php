<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\txn\TransactionStatus;
use WeakMap;

class TransactionsHolder {
    protected array $stack;
    protected int $pos = 0;
    protected WeakMap $map;

    public function __construct() {
        $this->stack = [];
        $this->map = new WeakMap();
    }

    public function push(TransactionStatus $txn): void {
        $this->map[$txn] = $this->pos;
        $this->stack[$this->pos++] = $txn;
    }

    public function pop(): ?TransactionStatus {
        $key = array_key_last($this->stack);
        if (is_null($key)) {
            return null;
        }
        $txn = $this->stack[$key];

        unset($this->stack[$key]);
        unset($this->map[$txn]);

        return $txn;
    }

    public function remove(TransactionStatus $txn): bool {
        if (!isset($this->map[$txn])) {
            return false;
        }

        unset($this->stack[$this->map[$txn]]);
        unset($this->map[$txn]);

        return true;
    }

    public function current(): ?TransactionStatus {
        $key = array_key_last($this->stack);
        if (is_null($key)) {
            return null;
        }
        return $this->stack[$key];
    }

    public function isEmpty(): bool {
        return count($this->stack) == 0;
    }
}