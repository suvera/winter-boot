<?php /** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVar;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\ex\IncorrectResultSizeDataAccessException;
use dev\winterframework\pdbc\PreparedStatement;
use dev\winterframework\ppa\EntityRegistry;
use dev\winterframework\ppa\PpaEntity;
use dev\winterframework\ppa\PpaObjectMapper;
use dev\winterframework\reflection\ObjectCreator;

abstract class PdoOperations {

    public function __construct(
        protected DataSource $dataSource
    ) {
    }

    protected function doExecute(
        string $sql,
        BindVars|array $bindVars = [],
        PreparedStatementCallback $action = null
    ): mixed {

        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);
        if ($action) {
            $ret = $action->doInPreparedStatement($stmt);
        } else {
            $ret = $stmt->execute();
        }
        $stmt->close();
        return $ret;
    }

    private function applyBindVars(PreparedStatement $stmt, BindVars|array $bindVars): void {
        if (empty($bindVars)) {
            return;
        }

        if (is_object($bindVars)) {
            $stmt->bindVars($bindVars);
        } else {
            foreach ($bindVars as $bindKey => $bindVal) {
                $stmt->bindValue($bindKey, $bindVal);
            }
        }
    }

    private function applyOutBindVars(PreparedStatement $stmt, OutBindVars|array $bindVars): void {
        if (empty($bindVars)) {
            return;
        }

        if (is_object($bindVars)) {
            $stmt->outBindVars($bindVars);
        } else {
            foreach ($bindVars as $bindKey => $bindVal) {
                $maxLen = $bindVal;
                $type = SQLT_CHR;
                if (is_array($bindVal) && isset($bindVal[0])) {
                    $maxLen = intval($bindVal[0]);
                } else if (is_array($bindVal) && isset($bindVal[1])) {
                    $type = intval($bindVal[1]);
                }
                $stmt->outBindVar(new OutBindVar($bindKey, $maxLen, $type));
            }
        }
    }

    protected function doQuery(
        string $sql,
        BindVars|array $bindVars,
        RowCallbackHandler|RowMapper|ResultSetExtractor $processor
    ): mixed {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);

        $rs = $stmt->executeQuery();
        if ($processor instanceof ResultSetExtractor) {
            $ret = $processor->extractData($rs);
        } else if ($processor instanceof RowCallbackHandler) {
            $ret = null;
            while ($rs->next()) {
                $processor->processRow($rs);
            }
        } else {
            $ret = [];
            $num = 0;
            while ($rs->next()) {
                $ret[] = $processor->mapRow($rs, $num++);
            }
        }

        $stmt->close();
        return $ret;
    }

    protected function doQueryForList(
        string $sql,
        BindVars|array $bindVars,
        string $class = null
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);

        $rs = $stmt->executeQuery();

        $ret = [];
        while ($rs->next()) {
            if ($class == null) {
                $ret[] = $rs->getRow();
            } else {
                $ret[] = ObjectCreator::createObject($class, $rs->getRow());
            }
        }

        $stmt->close();
        return $ret;
    }

    private function doQueryForSingleRow(
        string $sql,
        BindVars|array $bindVars
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);

        $rs = $stmt->executeQuery();

        $ret = [];
        $num = 0;
        while ($rs->next()) {
            $ret[] = $rs->getRow();
            $num++;
            if ($num > 1) {
                break;
            }
        }
        $stmt->close();
        if ($num != 1) {
            throw new IncorrectResultSizeDataAccessException('Incorrect result size: expected: 1 '
                . ', actual  ' . ($num > 1 ? 'more than one record' : ' empty record'));
        }
        return $ret[0];
    }

    protected function doQueryForMap(
        string $sql,
        BindVars|array $bindVars
    ): array {
        return $this->doQueryForSingleRow($sql, $bindVars);
    }

    protected function doQueryForScalar(
        string $sql,
        BindVars|array $bindVars
    ): mixed {
        $row = $this->doQueryForSingleRow($sql, $bindVars);
        return $row[0];
    }

    protected function doQueryForObject(
        string $sql,
        BindVars|array $bindVars,
        RowMapper|string $classOrMapper = null
    ): object {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);
        $rs = $stmt->executeQuery();

        $ret = [];
        $num = 0;
        while ($rs->next()) {
            if ($classOrMapper instanceof RowMapper) {
                $ret[] = $classOrMapper->mapRow($rs, $num);
            } else {
                $ret[] = ObjectCreator::createObject($classOrMapper, $rs->getRow());
            }
            $num++;
            if ($num > 1) {
                break;
            }
        }
        $stmt->close();
        if ($num != 1) {
            throw new IncorrectResultSizeDataAccessException('Incorrect result size: expected: 1 '
                . ', actual  ' . ($num > 1 ? 'more than one record' : ' empty record'));
        }
        return $ret[0];
    }

    protected function doUpdate(
        string $sql,
        BindVars|array $bindVars,
        array|OutBindVars $outBindVars = [],
        array &$generatedKeys = []
    ): int {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);
        $this->applyOutBindVars($stmt, $outBindVars);

        $ret = $stmt->executeUpdate();

        foreach ($stmt->getGeneratedKeys() as $key => $value) {
            $key = (substr($key, 0, 2) == 'b_') ? substr($key, 2) : $key;
            $generatedKeys[$key] = $value;
        }

        return $ret;
    }

    protected function doBatchUpdate(
        string $sql,
        array $arrayBindVars
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        $ret = [];
        foreach ($arrayBindVars as $bindVars) {
            $stmt->clearParameters();
            $this->applyBindVars($stmt, $bindVars);
            $ret[] = $stmt->executeUpdate();
        }

        return $ret;
    }

    public function queryForObjects(string $sql, BindVars|array $bindVars, string $ppaClass): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);

        $rs = $stmt->executeQuery();

        $ret = [];
        while ($rs->next()) {
            $ent = new $ppaClass();
            PpaObjectMapper::mapObject($ent, $rs->getRow(), EntityRegistry::getEntity($ppaClass));
            $ret[] = $ent;
        }

        $stmt->close();
        return $ret;
    }

    public function updateObjects(object ...$ppaObjects): void {
        foreach ($ppaObjects as $ppaObject) {
            $generatedKeys = [];
            $entity = EntityRegistry::getEntity($ppaObject::class);

            /** @var PpaEntity $ppaObject */
            if ($ppaObject->isStored()) {
                $sql = PpaObjectMapper::generateUpdateSql($ppaObject, $entity);
                if (!isset($sql[0])) {
                    continue;
                }
                $this->doUpdate($sql[0], $sql[1]);
            } else {
                $sql = PpaObjectMapper::generateInsertSql($ppaObject, $entity);
                if (!isset($sql[0])) {
                    continue;
                }
                $this->doUpdate($sql[0], $sql[1], $sql[2], $generatedKeys);
                if ($generatedKeys) {
                    PpaObjectMapper::mapObject($ppaObject, $generatedKeys, $entity);
                }
                $ppaObject->setStored(true);
            }
        }
    }

    public function deleteObjects(object ...$ppaObjects): void {
        foreach ($ppaObjects as $ppaObject) {
            $entity = EntityRegistry::getEntity($ppaObject::class);
            /** @var PpaEntity $ppaObject */

            $sql = PpaObjectMapper::generateDeleteSql($ppaObject, $entity);
            if (!isset($sql[0])) {
                continue;
            }
            $this->doUpdate($sql[0], $sql[1]);
        }
    }

}