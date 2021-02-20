<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface SavepointManager {

    public function rollbackToSavepoint(Savepoint $point): void;

    public function releaseSavepoint(Savepoint $point): void;

    public function createSavepoint(): Savepoint;

}