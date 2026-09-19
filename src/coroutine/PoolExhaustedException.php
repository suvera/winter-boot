<?php

declare(strict_types=1);

namespace dev\winterframework\coroutine;

/**
 * Thrown when a coroutine-scoped delegate pool has reached its configured
 * cap (`doctrine.coroutineMaxDelegates`) and no slot frees up within
 * `doctrine.coroutineMaxWaitMs`.
 *
 * This is resource backpressure, not a machinery failure: the caller asked
 * for more concurrent database users than the operator allowed. Either raise
 * the cap (and the database's `max_connections` to match) or stop fanning
 * out so many concurrent database users.
 */
class PoolExhaustedException extends \RuntimeException {

    public function __construct(
        private string $poolName,
        private int $activeDelegates,
        private int $maxDelegates
    ) {
        parent::__construct(
            "Doctrine DB connection pool '" . $poolName . "' exhausted: "
                . $activeDelegates . ' open DB connections (max ' . $maxDelegates . '). '
                . 'Raise doctrine.coroutineMaxDelegates (and the database max_connections to match) '
                . 'or reduce concurrent database users.'
        );
    }

    public function getPoolName(): string {
        return $this->poolName;
    }

    public function getActiveDelegates(): int {
        return $this->activeDelegates;
    }

    public function getMaxDelegates(): int {
        return $this->maxDelegates;
    }
}
