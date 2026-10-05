<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

use dev\winterframework\pdbc\Connection;

/**
 * A DataSource that can temporarily bind a dedicated connection to the
 * current scope (request/coroutine). While bound, getConnection() returns
 * it, so work runs outside the scope's regular connection and its
 * transaction. Used by PROPAGATION_REQUIRES_NEW / PROPAGATION_NOT_SUPPORTED.
 * Calls nest: every beginIsolation() must be paired with endIsolation().
 */
interface IsolatedConnectionProvider {
    public function beginIsolation(): Connection;

    public function endIsolation(): void;
}
