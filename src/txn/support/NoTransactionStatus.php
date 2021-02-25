<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

class NoTransactionStatus extends AbstractTransactionStatus {

    public function __construct() {
        parent::__construct();
    }

    public function flush(): void {
        // do nothing
    }

    public function hasSavepoint(): bool {
        return false;
    }

    public function isRollbackOnly(): bool {
        return false;
    }

    public function isNewTransaction(): bool {
        return false;
    }

    public function createAndHoldSavepoint(): void {
        // do nothing
    }


}