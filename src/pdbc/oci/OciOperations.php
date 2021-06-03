<?php /** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\ex\IncorrectResultSizeDataAccessException;
use dev\winterframework\pdbc\PreparedStatement;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\type\TypeAssert;

abstract class OciOperations {

    public function __construct(
        protected DataSource $dataSource
    ) {
        TypeAssert::objectOf($this->dataSource, OciDataSource::class);
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
        array &$generatedKeys = []
    ): int {
        $stmt = $this->dataSource->getConnection()->prepareStatement($sql);
        $this->applyBindVars($stmt, $bindVars);

        $ret = $stmt->executeUpdate();

        foreach ($stmt->getGeneratedKeys() as $key => $value) {
            $generatedKeys[$key] = $value;
        }

        $stmt->close();
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

        $stmt->close();
        return $ret;
    }

}