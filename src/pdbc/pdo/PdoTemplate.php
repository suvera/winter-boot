<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Component;

#[Component]
class PdoTemplate extends PdoOperations implements PdbcTemplate {

    public function execute(
        string $sql,
        array|BindVars $bindVars = [],
        PreparedStatementCallback $action = null
    ): mixed {
        return $this->doExecute($sql, $bindVars, $action);
    }

    public function query(
        string $sql,
        BindVars|array $bindVars,
        callable|RowCallbackHandler|RowMapper|ResultSetExtractor $processor
    ): mixed {
        return $this->doQuery($sql, $bindVars, $processor);
    }

    /**
     * @inheritDoc
     */
    public function queryForList(
        string $sql,
        BindVars|array $bindVars = []
    ): array {
        return $this->doQueryForList($sql, $bindVars);
    }

    /**
     * @inheritDoc
     */
    public function queryForMap(
        string $sql,
        BindVars|array $bindVars = []
    ): array {
        return $this->doQueryForMap($sql, $bindVars);
    }

    public function queryForObject(
        string $sql,
        BindVars|array $bindVars,
        RowMapper|string $classOrMapper = null
    ): object {
        return $this->doQueryForObject($sql, $bindVars, $classOrMapper);
    }

    public function queryForScalar(string $sql, BindVars|array $bindVars = []): int|string|float|bool|null {
        return $this->doQueryForScalar($sql, $bindVars);
    }

    /**
     * @inheritDoc
     */
    public function update(
        string $sql,
        BindVars|array $bindVars,
        array|OutBindVars $outBindVars = [],
        array &$generatedKeys = []
    ): int {
        return $this->doUpdate($sql, $bindVars, $outBindVars, $generatedKeys);
    }

    public function batchUpdate(
        string $sql,
        array $arrayBindVars
    ): array {
        return $this->doBatchUpdate($sql, $arrayBindVars);
    }

}