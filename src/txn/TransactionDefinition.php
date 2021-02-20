<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

use Stringable;

interface TransactionDefinition extends Stringable {
    const PROPAGATION_REQUIRED = 0;
    const PROPAGATION_SUPPORTS = 1;
    const PROPAGATION_MANDATORY = 2;
    const PROPAGATION_REQUIRES_NEW = 3;
    const PROPAGATION_NOT_SUPPORTED = 4;
    const PROPAGATION_NEVER = 5;
    const PROPAGATION_NESTED = 6;
    const ISOLATION_DEFAULT = -1;
    const ISOLATION_READ_UNCOMMITTED = 1;
    const ISOLATION_READ_COMMITTED = 2;
    const ISOLATION_REPEATABLE_READ = 4;
    const ISOLATION_SERIALIZABLE = 8;
    const TIMEOUT_DEFAULT = -1;

    public function getName(): string;

    public function isReadOnly(): bool;

    public function getPropagationBehavior(): int;

    public function getIsolationLevel(): int;

    public function getTimeout(): int;

}
