<?php
/** @noinspection PhpUnused */
/** @noinspection DuplicatedCode */
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
use dev\winterframework\ppa\PpaObjectMapperFactory;
use dev\winterframework\reflection\ObjectCreator;
use Throwable;

abstract class PdoOperations {

    public function __construct(
        protected DataSource $dataSource
    ) {
    }

    /**
     * @throws
     */
    protected function doExecute(
        string $sql,
        BindVars|array $bindVars = [],
        PreparedStatementCallback $action = null
    ): mixed {

        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        try {
            $this->applyBindVars($stmt, $bindVars);
            if ($action) {
                $ret = $action->doInPreparedStatement($stmt);
            } else {
                $ret = $stmt->execute();
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
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

    /**
     * @throws
     */
    protected function doQuery(
        string $sql,
        BindVars|array $bindVars,
        callable|RowCallbackHandler|RowMapper|ResultSetExtractor $processor
    ): mixed {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $this->applyBindVars($stmt, $bindVars);
            $rs = $stmt->executeQuery();
            if ($processor instanceof ResultSetExtractor) {
                $ret = $processor->extractData($rs);
            } else if ($processor instanceof RowCallbackHandler) {
                $ret = null;
                while ($rs->next()) {
                    $processor->processRow($rs);
                }
            } else if (is_callable($processor)) {
                $ret = null;
                $i = 0;
                while ($rs->next()) {
                    $processor($rs, $i++);
                }
            } else {
                $ret = [];
                $num = 0;
                while ($rs->next()) {
                    $ret[] = $processor->mapRow($rs, $num++);
                }
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
        return $ret;
    }

    /**
     * @throws
     */
    protected function doQueryForList(
        string $sql,
        BindVars|array $bindVars
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $this->applyBindVars($stmt, $bindVars);
            $rs = $stmt->executeQuery();
            $ret = [];
            while ($rs->next()) {
                $ret[] = $rs->getRow();
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
        return $ret;
    }

    /**
     * @throws
     */
    private function doQueryForSingleRow(
        string $sql,
        BindVars|array $bindVars
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
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
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
        if ($num != 1) {
            throw new IncorrectResultSizeDataAccessException('Incorrect result size: expected: 1 '
                . ', actual  ' . ($num > 1 ? 'more than one record' : ' empty record'));
        }
        return $ret[0];
    }

    /**
     * @throws
     */
    protected function doQueryForMap(
        string $sql,
        BindVars|array $bindVars
    ): array {
        return $this->doQueryForSingleRow($sql, $bindVars);
    }

    /**
     * @throws
     */
    protected function doQueryForScalar(
        string $sql,
        BindVars|array $bindVars
    ): mixed {
        $row = $this->doQueryForSingleRow($sql, $bindVars);
        return $row[0];
    }

    /**
     * @throws
     */
    protected function doQueryForObject(
        string $sql,
        BindVars|array $bindVars,
        RowMapper|string $classOrMapper = null
    ): object {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $this->applyBindVars($stmt, $bindVars);
            $rs = $stmt->executeQuery();
            $ret = [];
            $num = 0;
            while ($rs->next()) {
                if ($classOrMapper instanceof RowMapper) {
                    $ret[] = $classOrMapper->mapRow($rs, $num);
                } else if (is_a($classOrMapper, PpaEntity::class, true)) {
                    $ent = new $classOrMapper();
                    PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                        ->mapObject($ent, $rs->getRow(), EntityRegistry::getEntity($classOrMapper));
                    $ret[] = $ent;
                } else {
                    $ret[] = ObjectCreator::createObject($classOrMapper, $rs->getRow());
                }
                $num++;
                if ($num > 1) {
                    break;
                }
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
        if ($num != 1) {
            throw new IncorrectResultSizeDataAccessException('Incorrect result size: expected: 1 '
                . ', actual  ' . ($num > 1 ? 'more than one record' : ' empty record'));
        }
        return $ret[0];
    }

    /**
     * @throws
     */
    protected function doUpdate(
        string $sql,
        BindVars|array $bindVars,
        array|OutBindVars $outBindVars = [],
        array &$generatedKeys = []
    ): int {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $this->applyBindVars($stmt, $bindVars);
            $this->applyOutBindVars($stmt, $outBindVars);
            $ret = $stmt->executeUpdate();
            foreach ($stmt->getGeneratedKeys() as $key => $value) {
                $key = (substr($key, 0, 2) == 'b_') ? substr($key, 2) : $key;
                $generatedKeys[$key] = $value;
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }

        return $ret;
    }

    /**
     * @throws
     */
    protected function doBatchUpdate(
        string $sql,
        array $arrayBindVars
    ): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $ret = [];
            foreach ($arrayBindVars as $bindVars) {
                $stmt->clearParameters();
                $this->applyBindVars($stmt, $bindVars);
                $ret[] = $stmt->executeUpdate();
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }

        return $ret;
    }

    /**
     * @throws
     */
    public function queryForObjects(string $sql, BindVars|array $bindVars, string $ppaClass): array {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);

        try {
            $this->applyBindVars($stmt, $bindVars);
            $rs = $stmt->executeQuery();
            $ret = [];
            while ($rs->next()) {
                /** @var PpaEntity $ent */
                $ent = new $ppaClass();
                PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                    ->mapObject($ent, $rs->getRow(), EntityRegistry::getEntity($ppaClass));
                $ent->setStored(true);
                $ret[] = $ent;
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $stmt->close();
            unset($stmt);
        }
        return $ret;
    }

    /**
     * @throws
     */
    public function updateObjects(object ...$ppaObjects): void {
        foreach ($ppaObjects as $ppaObject) {
            $generatedKeys = [];
            $entity = EntityRegistry::getEntity($ppaObject::class);

            /** @var PpaEntity $ppaObject */
            if ($ppaObject->isStored()) {
                $sql = PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                    ->generateUpdateSql($ppaObject, $entity);
                $this->doUpdate($sql->getSql(), $sql->getBindVars());
            } else {
                $sql = PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                    ->generateInsertSql($ppaObject, $entity);
                $this->doUpdate($sql->getSql(), $sql->getBindVars(), $sql->getOutBindVars(), $generatedKeys);
                if ($generatedKeys) {
                    PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                        ->mapObject($ppaObject, $generatedKeys, $entity);
                }
                $ppaObject->setStored(true);
            }
        }
    }

    /**
     * @throws
     */
    public function deleteObjects(object ...$ppaObjects): void {
        foreach ($ppaObjects as $ppaObject) {
            $entity = EntityRegistry::getEntity($ppaObject::class);
            /** @var PpaEntity $ppaObject */

            $sql = PpaObjectMapperFactory::getMapper($this->dataSource->getConnection()->getDriverType())
                ->generateDeleteSql($ppaObject, $entity);
            $this->doUpdate($sql->getSql(), $sql->getBindVars());
        }
    }

}