<?php
declare(strict_types=1);

namespace dev\winterframework\txn;

interface Transaction {

    /**
     * ---------------------------------------------------
     * Propagation
     *
     * Support a current transaction; create a new one if none exists.
     * (default one)
     */
    const PROPAGATION_REQUIRED = 0;

    /**
     * Support a current transaction; execute non-transactional if none exists.
     */
    const PROPAGATION_SUPPORTS = 1;

    /**
     * Support a current transaction; throw an exception if no current transaction exists.
     */
    const PROPAGATION_MANDATORY = 2;

    /**
     * Create a new transaction, suspending the current transaction if one exists.
     */
    const PROPAGATION_REQUIRES_NEW = 3;

    /**
     * Do not support a current transaction; rather always execute non-transactional.
     */
    const PROPAGATION_NOT_SUPPORTED = 4;

    /**
     * Do not support a current transaction; throw an exception if a current transaction exists.
     */
    const PROPAGATION_NEVER = 5;

    /**
     * Execute within a nested transaction if a current transaction exists.
     *  behave like PROPAGATION_REQUIRED
     *
     * - nested transactions are not supported by PDO drivers
     */
    const PROPAGATION_NESTED = 6;

    /**
     * ----------------------------------------------------------
     * Isolation
     *
     */
    const ISOLATION_DEFAULT = -1;

    /**
     * Indicates that dirty reads, non-repeatable reads and phantom reads can occur.
     */
    const ISOLATION_READ_UNCOMMITTED = 1;

    /**
     * Indicates that dirty reads are prevented; non-repeatable reads and
     * phantom reads can occur.
     */
    const ISOLATION_READ_COMMITTED = 2;

    /**
     * Indicates that dirty reads and non-repeatable reads are prevented;
     * phantom reads can occur.
     */
    const ISOLATION_REPEATABLE_READ = 4;

    /**
     * Indicates that dirty reads, non-repeatable reads and phantom reads
     * are prevented.
     */
    const ISOLATION_SERIALIZABLE = 8;


    /**
     * -------------------------------------------------
     * SYNCHRONIZATION
     *
     * Always activate transaction synchronization, even for "empty" transactions
     * that result from PROPAGATION_SUPPORTS with no existing backend transaction.
     */
    const SYNCHRONIZATION_ALWAYS = 0;

    /**
     * Activate transaction synchronization only for actual transactions,
     * that is, not for empty ones that result from PROPAGATION_SUPPORTS with
     * no existing backend transaction.
     */
    const SYNCHRONIZATION_ON_ACTUAL_TRANSACTION = 1;

    /**
     * Never active transaction synchronization, not even for actual transactions.
     */
    const SYNCHRONIZATION_NEVER = 2;


    const TIMEOUT_DEFAULT = -1;
}