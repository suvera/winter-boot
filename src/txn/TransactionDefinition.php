<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

use Stringable;

interface TransactionDefinition extends Stringable, Transaction {

    public function getName(): string;

    public function isReadOnly(): bool;

    public function getPropagationBehavior(): int;

    public function getIsolationLevel(): int;

    public function getTimeout(): int;

}
