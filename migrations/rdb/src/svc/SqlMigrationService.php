<?php

declare(strict_types=1);

namespace dev\winterframework\migrations\rdb\svc;

use dev\winterframework\pdbc\multitenant\MultiTenantManager;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\pdbc\datasource\DataSourceBuilder;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\migrations\rdb\SqlMigrationException;
use dev\winterframework\core\app\WinterCliArguments;

#[Service]
class SqlMigrationService {
    use Wlf4p;

    private const MIGRATIONS_TABLE_PREFIX = 'winter_migrations';

    #[Autowired]
    private ApplicationContext $appCtx;

    private string $sqlBasePath;
    private array $datasourceConfigs = [];
    private array $multitenantConfigs = [];

    public function __construct() {
        $args = new WinterCliArguments();
        $sqlBasePath = $args->get('sqlPath');
        if ($sqlBasePath) {
            $this->sqlBasePath = $sqlBasePath;
        } else {
            throw new SqlMigrationException("SQL base path not provided. Use --sqlPath option to specify the path.");
        }

        $this->sqlBasePath = rtrim($sqlBasePath, '/');
    }

    public function executeMigrations(): void {
        $this->logInfo("Starting SQL migrations execution from: {$this->sqlBasePath}");

        $this->loadDatasourceConfigs();

        $allConfigs = count($this->datasourceConfigs) + count($this->multitenantConfigs);

        if (empty($allConfigs)) {
            $this->logInfo("No data sources with migrations enabled found");
            return;
        }

        foreach ($this->datasourceConfigs as $config) {
            $this->executeMigrationsForDatasource($config);
        }

        foreach ($this->multitenantConfigs as $config) {
            $this->executeMigrationsForMultiTenantDatasource($config);
        }

        $this->logInfo("All SQL migrations executed successfully");
    }

    private function loadDatasourceConfigs(): void {
        $datasources = $this->appCtx->getProperty('datasource', []);

        if (!is_array($datasources)) {
            return;
        }

        foreach ($datasources as $ds) {
            if (!isset($ds['name'])) {
                continue;
            }

            $migrations = $ds['migrations'] ?? [];
            if (!empty($migrations) && ($migrations['enabled'] ?? false)) {
                $this->datasourceConfigs[] = $ds;
            }
        }

        $multitenantDss = $this->appCtx->getProperty('multitenant-datasource', []);

        if (!is_array($multitenantDss)) {
            return;
        }

        foreach ($multitenantDss as $mtds) {
            if (!isset($mtds['name'])) {
                continue;
            }

            $migrations = $mtds['migrations'] ?? [];
            if (!empty($migrations) && ($migrations['enabled'] ?? false)) {
                $this->multitenantConfigs[] = $mtds;
            }
        }
    }

    private function executeMigrationsForDatasource(array $config): void {
        $dsName = $config['name'];
        $this->logInfo("Processing migrations for datasource: {$dsName}");

        $dsPath = $this->sqlBasePath . '/' . $dsName;

        if (!is_dir($dsPath)) {
            $this->logWarning("SQL folder not found for datasource {$dsName}: {$dsPath}");
            return;
        }

        $sqlFiles = $this->collectSqlFiles($dsPath);

        if (empty($sqlFiles)) {
            $this->logInfo("No SQL files found in {$dsPath}");
            return;
        }

        $template = $this->getPdbcTemplate($config);

        foreach ($sqlFiles as $sqlFile) {
            $this->executeSqlFile($template, $config, $sqlFile);
        }
    }

    private function collectSqlFiles(string $baseDir): array {
        return DirectoryScanner::scanForSqlFiles($baseDir);
    }

    private function getPdbcTemplate(array $config): PdbcTemplate {
        $name = $config['name'];
        $tpl = $this->appCtx->beanByNameClass($name . DataSourceBuilder::TEMPLATE_SUFFIX, PdbcTemplate::class);

        if ($tpl === null) {
            throw new SqlMigrationException("PdbcTemplate not found for datasource: {$name}");
        }
        return $tpl;
    }

    private function executeMigrationsForMultiTenantDatasource(array $config): void {
        $dsName = $config['name'];
        $this->logInfo("Processing migrations for multi-tenant datasource: {$dsName}");

        $dsPath = $this->sqlBasePath . '/' . $dsName;

        if (!is_dir($dsPath)) {
            $this->logWarning("SQL folder not found for multi-tenant datasource {$dsName}: {$dsPath}");
            return;
        }

        $sqlFiles = $this->collectSqlFiles($dsPath);

        if (empty($sqlFiles)) {
            $this->logInfo("No SQL files found in {$dsPath}");
            return;
        }

        /** @var MultiTenantManager $mtManager */
        $mtManager = $this->appCtx->beanByClass(MultiTenantManager::class);

        if ($mtManager === null) {
            throw new SqlMigrationException("MultiTenantManager not found for multi-tenant datasource: {$dsName}");
        }

        $tenantIds = $mtManager->getTenantDataSourceProvider()->getAllTenantIds();
        if (empty($tenantIds)) {
            $this->logInfo("No tenants found for multi-tenant datasource: {$dsName}");
            return;
        }

        foreach ($tenantIds as $tenantId) {
            $this->logInfo("Executing migrations for tenant: {$tenantId}");

            foreach ($sqlFiles as $sqlFile) {
                $template = $mtManager->getPdbcTemplate($tenantId);
                if ($template === null) {
                    throw new SqlMigrationException("PdbcTemplate not found for tenant: {$tenantId}");
                }
                $this->executeSqlFile($template, $config, $sqlFile);
            }
        }
    }

    private function executeSqlFile(PdbcTemplate $template, array $config, array $fileInfo): void {
        $relativePath = $fileInfo['relative'];
        $fullPath = $fileInfo['path'];

        if (!$this->isMigrationRequired($template, $config, $relativePath)) {
            $this->logInfo("Migration already executed, skipping: {$relativePath}");
            return;
        }

        $this->logInfo("Executing migration: {$relativePath}");

        $sqlContent = file_get_contents($fullPath);

        if ($sqlContent === false) {
            throw new SqlMigrationException("Failed to read SQL file: {$fullPath}");
        }

        $sqlStatements = $this->parseSqlStatements($sqlContent);

        foreach ($sqlStatements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || $statement[0] === '#') {
                continue;
            }

            $template->execute($statement);
        }

        $this->recordMigration($template, $config, $relativePath);
        $this->logInfo("Migration executed successfully: {$relativePath}");
    }

    private function isMigrationRequired(PdbcTemplate $template, array $config, string $relativePath): bool {
        $tableName = $this->getMigrationsTableName($template);

        try {
            $template->queryForScalar("SELECT 1 FROM {$tableName} LIMIT 1");
        } catch (\Throwable $e) {
            $this->createMigrationsTable($template, $config);
        }

        $count = $template->queryForScalar(
            "SELECT COUNT(*) FROM {$tableName} WHERE migration_path = ?",
            [$relativePath]
        );

        return $count == 0;
    }

    private function createMigrationsTable(PdbcTemplate $template, array $config): void {
        $tableName = $this->getMigrationsTableName($template);
        $driverType = $this->getDriverType($config);

        $createSql = $this->getCreateTableSql($tableName, $driverType);

        $this->logInfo("Creating migrations table: {$tableName}");
        $template->execute($createSql);
    }

    private function getCreateTableSql(string $tableName, string $driverType): string {
        switch ($driverType) {
            case 'mysql':
                return "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    migration_path VARCHAR(512) NOT NULL UNIQUE,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    executed_by VARCHAR(100) DEFAULT USER()
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            case 'pgsql':
                return "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id BIGSERIAL PRIMARY KEY,
                    migration_path VARCHAR(512) NOT NULL UNIQUE,
                    executed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    executed_by VARCHAR(100) DEFAULT CURRENT_USER
                )";

            case 'sqlite':
                return "CREATE TABLE IF NOT EXISTS {$tableName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration_path VARCHAR(512) NOT NULL UNIQUE,
                    executed_at DATETIME DEFAULT (datetime('now')),
                    executed_by VARCHAR(100)
                )";

            case 'oci':
                return "CREATE TABLE {$tableName} (
                    id NUMBER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    migration_path VARCHAR2(512) NOT NULL UNIQUE,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    executed_by VARCHAR2(100) DEFAULT USER
                )";

            case 'sqlsrv':
            case 'dblib':
                return "CREATE TABLE {$tableName} (
                    id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    migration_path VARCHAR(512) NOT NULL UNIQUE,
                    executed_at DATETIME2 DEFAULT GETDATE(),
                    executed_by VARCHAR(100) DEFAULT SYSTEM_USER
                )";

            default:
                return "CREATE TABLE {$tableName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration_path VARCHAR(512) NOT NULL UNIQUE,
                    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    executed_by VARCHAR(100)
                )";
        }
    }

    private function recordMigration(PdbcTemplate $template, array $config, string $relativePath): void {
        $tableName = $this->getMigrationsTableName($template);

        $template->execute(
            "INSERT INTO {$tableName} (migration_path) VALUES (?)",
            [$relativePath]
        );
    }

    private function getMigrationsTableName(PdbcTemplate $template): string {
        return self::MIGRATIONS_TABLE_PREFIX;
    }

    private function getDriverType(array $config): string {
        $url = $config['url'] ?? '';
        // DSN URL format: driver://username:password@host:port/database
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            return 'unknown';
        }

        return $parts['scheme'];
    }

    private function parseSqlStatements(string $sqlContent): array {
        $statements = [];
        $currentStatement = '';
        $inString = false;
        $inComment = false;
        $stringChar = '';

        $length = strlen($sqlContent);

        for ($i = 0; $i < $length; $i++) {
            $char = $sqlContent[$i];
            $nextChar = ($i + 1 < $length) ? $sqlContent[$i + 1] : '';

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                }
                continue;
            }

            if ($inString) {
                $currentStatement .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $currentStatement .= $nextChar;
                    $i++;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '#' || ($char === '-' && $nextChar === '-')) {
                $inComment = true;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $currentStatement .= $char;
                continue;
            }

            $currentStatement .= $char;

            if ($char === ';') {
                $trimmed = trim($currentStatement);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $currentStatement = '';
            }
        }

        $trimmed = trim($currentStatement);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
