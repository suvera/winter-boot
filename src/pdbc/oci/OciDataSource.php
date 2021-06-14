<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\support\AbstractDataSource;
use PDO;

class OciDataSource extends AbstractDataSource {

    private static array $defaultOptions = [
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_AUTOCOMMIT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
        PDO::ATTR_PREFETCH => 100,
    ];

    private array $ociOptions = [];

    private OciConnection $connection;
    private string $dsn;
    private string $username;
    private string $password;

    public function __construct(
        protected DataSourceConfig $config
    ) {
        parent::__construct($config);
        $this->initConfig();
    }

    public function getConnection(): OciConnection {
        if (!isset($this->connection)) {
            $this->connection = new OciConnection(
                $this->dsn,
                $this->username,
                $this->password,
                $this->ociOptions
            );

            $this->validateConnection();
        }
        return $this->connection;
    }

    private function initConfig(): void {
        $this->dsn = $this->config->getUrl();
        $this->username = $this->config->getUsername();
        $this->password = $this->config->getPassword();

        if (!isset($_ENV['NLS_LANG']) || empty($_ENV['NLS_LANG'])) {
            self::$defaultOptions['NLS_LANG'] = 'AMERICAN_AMERICA.UTF8';
        } else {
            self::$defaultOptions['NLS_LANG'] = $_ENV['NLS_LANG'];
        }

        if (!isset($_ENV['NLS_DATE_FORMAT']) || empty($_ENV['NLS_DATE_FORMAT'])) {
            self::$defaultOptions['NLS_DATE_FORMAT'] = 'YYYY-MM-DD HH24:MI:SS';
        } else {
            self::$defaultOptions['NLS_DATE_FORMAT'] = $_ENV['NLS_DATE_FORMAT'];
        }

        $this->ociOptions = self::$defaultOptions;
        $this->ociOptions[PDO::ATTR_PERSISTENT] = $this->config->isPersistent();
        $this->ociOptions[PDO::ATTR_TIMEOUT] = $this->config->getTimeoutSecs();
        $this->ociOptions[PDO::ATTR_AUTOCOMMIT] = $this->config->isAutoCommit();
        $this->ociOptions[PDO::ATTR_PREFETCH] = $this->config->getRowsPrefetch();
        $this->ociOptions[PDO::ATTR_PREFETCH] = $this->config->getRowsPrefetch();
        $this->ociOptions['idleTimeout'] = $this->config->getIdleTimeout();
    }

    private function validateConnection(): void {
        $validateSql = $this->config->getValidationQuery();
        if (empty($validateSql)) {
            return;
        }

        $stmt = $this->connection->createStatement();
        $resultSet = $stmt->executeQuery($validateSql);
        $resultSet->next();

        self::logInfo("ValidationQuery:" . $resultSet->getString(0));
        $stmt->close();
        $resultSet = null;
    }

}