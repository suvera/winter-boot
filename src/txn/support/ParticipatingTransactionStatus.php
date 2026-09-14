<?php
declare(strict_types=1);

namespace dev\winterframework\txn\support;

use dev\winterframework\txn\TransactionObject;

// WB-001: non-owning participant view of an existing transaction.
class ParticipatingTransactionStatus extends AbstractTransactionStatus {

    public function __construct(?TransactionObject $transaction = null) {
        parent::__construct($transaction, false);
    }
}
