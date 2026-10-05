<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\pdo;

use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\support\AbstractDataSource;
use dev\winterframework\pdbc\support\IsolatedConnectionProvider;
use dev\winterframework\pdbc\support\ScopedConnectionPool;
use dev\winterframework\stereotype\Value;
use PDO;
use Throwable;

class PdoDataSource extends AbstractDataSource implements IsolatedConnectionProvider {
    use ScopedConnectionPool;

    private static array $defaultOptions = [
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_AUTOCOMMIT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
        PDO::ATTR_PREFETCH => 100
    ];

    /**
     * Master switch for coroutine-scoped pooling. Defaults on: pooling
     * additionally requires a live Swoole coroutine, so non-coroutine
     * runtimes always use the shared connection below.
     */
    #[Value('${winter.coroutine.db.enabled}', true)]
    protected bool $coroutineDbEnabled = true;

    private array $pdoOptions = [];

    private PdoConnection $connection;
    private string $dsn;
    private string $username;
    private string $password;

    public function __construct(
        protected DataSourceConfig $config
    ) {
        parent::__construct($config);
        $this->initConfig();
    }

    public function getConnection(): PdoConnection {
        /** @var PdoConnection $conn */
        $conn = $this->pooledConnection();
        return $conn;
    }

    protected function createConnection(): PdoConnection {
        $conn = new PdoConnection(
            $this->dsn,
            $this->username,
            $this->password,
            $this->pdoOptions
        );
        $this->validateConnection($conn);
        return $conn;
    }

    private function initConfig(): void {
        $this->dsn = $this->config->getUrl();
        $this->username = $this->config->getUsername();
        $this->password = $this->config->getPassword();

        $this->pdoOptions = self::$defaultOptions;
        $this->pdoOptions[PDO::ATTR_PERSISTENT] = $this->config->isPersistent();
        $this->pdoOptions[PDO::ATTR_TIMEOUT] = $this->config->getTimeoutSecs();
        $this->pdoOptions[PDO::ATTR_AUTOCOMMIT] = $this->config->isAutoCommit();
        $this->pdoOptions[PDO::ATTR_PREFETCH] = $this->config->getRowsPrefetch();

        $this->pdoOptions[PDO::ATTR_CASE] = match ($this->config->getColumnsCase()) {
            'CASE_LOWER' => PDO::CASE_LOWER,
            'CASE_UPPER' => PDO::CASE_UPPER,
            default => PDO::CASE_NATURAL,
        };
        $this->pdoOptions[PDO::ATTR_ERRMODE] = match ($this->config->getErrorMode()) {
            'ERRMODE_SILENT' => PDO::ERRMODE_SILENT,
            'ERRMODE_WARNING' => PDO::ERRMODE_WARNING,
            default => PDO::ERRMODE_EXCEPTION,
        };

        $this->pdoOptions['idleTimeout'] = $this->config->getIdleTimeout();
    }

    private function validateConnection(PdoConnection $connection): void {
        $validateSql = $this->config->getValidationQuery();
        if (empty($validateSql)) {
            return;
        }

        $stmt = $connection->createStatement();
        $resultSet = $stmt->executeQuery($validateSql);
        $resultSet->next();
        self::logInfo("ValidationQuery:" . $resultSet->getString(0));
    }

}
