<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;

interface PdbcTemplate {

    public function batchUpdate(
        string $sql,
        array $arrayBindVars
    ): array;

    public function execute(
        string $sql,
        array|BindVars $bindVars = [],
        PreparedStatementCallback $action = null
    ): mixed;

    public function query(
        string $sql,
        array|BindVars $bindVars,
        callable|ResultSetExtractor|RowCallbackHandler|RowMapper $processor
    ): mixed;

    /**
     * Return something like
     *
     *    [
     *        [
     *            key-1 => value-1,
     *            ....
     *        ]
     *    ]
     *
     * @param string $sql
     * @param array|BindVars $bindVars
     * @return array
     */
    public function queryForList(
        string $sql,
        array|BindVars $bindVars = []
    ): array;

    public function queryForScalar(
        string $sql,
        array|BindVars $bindVars = []
    ): int|string|float|bool|null;

    /**
     * Return a single row as associative array
     *
     * @param string $sql
     * @param array|BindVars $bindVars
     * @return array
     */
    public function queryForMap(
        string $sql,
        array|BindVars $bindVars = []
    ): array;

    public function queryForObject(
        string $sql,
        array|BindVars $bindVars,
        string|RowMapper $classOrMapper = null
    ): object;

    /**
     * Issue an update via a prepared statement, binding the given arguments, returning generated keys.
     *
     * @param string $sql
     * @param array|BindVars $bindVars
     * @param array|OutBindVars $outBindVars
     * @param array $generatedKeys
     * @return int
     */
    public function update(
        string $sql,
        array|BindVars $bindVars,
        array|OutBindVars $outBindVars = [],
        array &$generatedKeys = []
    ): int;

    /**
     * Create/Read ppa objects out of sql query
     *
     * @param string $sql
     * @param array|BindVars $bindVars
     * @param string $ppaClass
     * @return array
     */
    public function queryForObjects(
        string $sql,
        array|BindVars $bindVars,
        string $ppaClass
    ): array;

    /**
     * create or update objects
     *
     * @param object ...$ppaObjects
     */
    public function updateObjects(
        object ...$ppaObjects
    ): void;

    /**
     * create or update objects
     *
     * @param object ...$ppaObjects
     */
    public function deleteObjects(
        object ...$ppaObjects
    ): void;
}