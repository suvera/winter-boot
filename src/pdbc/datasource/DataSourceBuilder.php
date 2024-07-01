<?php

declare(strict_types=1);

namespace dev\winterframework\pdbc\datasource;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\pdo\PdoTemplate;
use dev\winterframework\pdbc\pdo\PdoTransactionManager;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use ReflectionClass;
use Throwable;
use WeakMap;

class DataSourceBuilder {
    use Wlf4p;

    const TXN_SUFFIX = '-txn';
    const TEMPLATE_SUFFIX = '-template';

    /**
     * @var DataSource[]
     */
    private array $dataSources = [];
    private DataSource $primaryDataSource;

    /**
     * @var DataSourceConfig[]
     */
    private array $dsConfig = [];

    private WeakMap $dsObjectMap;

    public function __construct(
        private ApplicationContext $ctx,
        private ApplicationContextData $ctxData,
        array $dataSources
    ) {
        $this->dsObjectMap = new WeakMap();
        $this->init($dataSources);
    }

    private function init(array $dataSources): void {
        $primary = false;
        $first = false;

        $ref = new ReflectionClass(DataSourceConfig::class);

        foreach ($dataSources as $dataSource) {

            TypeAssert::notEmpty(
                'name',
                $dataSource['name'],
                'DataSource configured without "name" parameter'
            );

            TypeAssert::notEmpty(
                'url',
                $dataSource['url'],
                'DataSource configured without "url" parameter'
            );

            if (isset($dataSource['driverClass'])) {
                if (!class_exists($dataSource['driverClass'], true)) {
                    throw new ClassNotFoundException('DataSource driverClass does not exist "'
                        . $dataSource['driverClass'] . '"');
                }
                TypeAssert::objectOfIsA(
                    $dataSource['driverClass'],
                    DataSource::class,
                    'DataSource "driverClass" must be derived from ' . DataSource::class
                );
            } else {
                $dataSource['driverClass'] = 'dev\\winterframework\\pdbc\\pdo\\PdoDataSource';
            }

            $ds = new DataSourceConfig();
            try {
                ObjectCreator::mapObject($ds, $dataSource, $ref);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Invalid Syntax in DataSource configuration ', 0, $e);
            }

            if ($primary && $ds->isPrimary()) {
                throw new WinterException('Two DataSources cannot have "isPrimary" set to "true"');
            }

            if ($ds->isPrimary()) {
                $primary = $ds;
            }
            if (!$first) {
                $first = $ds;
            }

            if (isset($this->dsConfig[$ds->getName()])) {
                throw new WinterException('Two DataSources cannot have same "name" "' . $ds->getName() . '"');
            }

            $this->dsConfig[$ds->getName()] = $ds;
        }

        if (!$primary && $first) {
            $first->setPrimary(true);
        }
    }

    /**
     * @return DataSource[]
     */
    public function getDataSources(): array {
        return $this->dataSources;
    }

    public function getPrimaryTransactionManager(): PlatformTransactionManager {
        return new PdoTransactionManager($this->ctx->beanByClass(DataSource::class));
    }

    public function getTransactionManager(string $name): PlatformTransactionManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::TXN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }
        return new PdoTransactionManager($this->getDataSource($name));
    }

    public function getPdbcTemplate(string $name): PdbcTemplate {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::TEMPLATE_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }
        return new PdoTemplate($this->getDataSource($name));
    }

    public function getPrimaryDataSource(): DataSource {
        if (!isset($this->primaryDataSource)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    $this->primaryDataSource = $this->buildDataSource($dsConfig);
                    return $this->primaryDataSource;
                }
            }
            throw new WinterException('Could not find Primary DataSource');
        }
        return $this->primaryDataSource;
    }

    public function getDataSource(string $name): DataSource {
        if (isset($this->dataSources[$name])) {
            return $this->dataSources[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->dataSources[$name] = $this->buildDataSource($this->dsConfig[$name]);
        }
        throw new WinterException('Could not find DataSource with name "' . $name . '"');
    }

    /**
     * @return DataSourceConfig[]
     */
    public function getDataSourceConfig(): array {
        return $this->dsConfig;
    }

    private function buildDataSource(DataSourceConfig $ds): DataSource {
        if (isset($this->dsObjectMap[$ds])) {
            return $this->dsObjectMap[$ds];
        }
        $driver = $ds->getDriverClass();

        self::logInfo("creating DataSource of type $driver");
        /** @var DataSource $obj */
        $obj = ReflectionUtil::createAutoWiredObject(
            $this->ctx,
            new RefKlass($driver),
            $ds
        );

        TypeAssert::typeOf($obj, DataSource::class);
        $this->dsObjectMap[$ds] = $obj;

        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $this->ctx->beanByClass(IdleCheckRegistry::class);
        $idleCheck->register([$obj, 'checkIdleConnection']);

        return $obj;
    }
}
