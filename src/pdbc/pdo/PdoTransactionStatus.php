<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\txn\support\AbstractTransactionStatus;

class PdoTransactionStatus extends AbstractTransactionStatus {

    public function __construct(
        private PdoTransactionObject $transaction,
        private bool $newTransaction = true,
        private bool $readOnly = false,
        private bool $debug = false
    ) {
        parent::__construct($transaction);
    }

    public function getTransaction(): PdoTransactionObject {
        return $this->transaction;
    }

    public function isNewTransaction(): bool {
        return $this->newTransaction;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function isDebug(): bool {
        return $this->debug;
    }

    public function flush(): void {
        $this->transaction->flush();
    }

    public function hasSavepoint(): bool {
        return isset($this->savepoint);
    }

    public function isRollbackOnly(): bool {
        return $this->transaction->isRollbackOnly();
    }

}