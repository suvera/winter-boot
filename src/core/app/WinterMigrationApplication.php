<?php

namespace dev\winterframework\core\app;

use dev\winterframework\migrations\OpenSearchMigrationService;
use dev\winterframework\migrations\SqlMigrationService;

final class WinterMigrationApplication  extends WinterApplicationRunner implements WinterApplication {

    public const MIGRATION_TYPE_OPENSEARCH = 'opensearch';
    public const MIGRATION_TYPE_SQL = 'sql';

    protected string $sqlBasePath;
    protected string $configDir;
    protected ?string $migrationType = null;

    public function __construct() {
        parent::__construct();
        $this->args->description('SQL Migration Application');
        $configDir = $this->args->getConfigDir();
        if (!$configDir) {
            $this->args->printError("Config directory not provided. Use --configDir option to specify the path.");
            $this->args->writeHelp();
            exit(1);
        } else {
            $this->configDir = $configDir;
        }
    }

    #[\Override]
    protected function startBootApp(): void {
        $appClass = $this->bootApp->getClass()->getName();
        $this->applicationContext->addClass($appClass);
        $this->applicationContext->beanByClass($appClass);

        $this->runBootApp();
    }

    #[\Override]
    protected function runBootApp(): void {
        $version = $this->args->getVersion();
        if ($version) {
            echo "Application Version: " . $this->applicationContext->getApplicationVersion() . PHP_EOL;
            exit(0);
        }
        $sqlBasePath = $this->args->getSqlPath();
        if ($sqlBasePath) {
            $this->sqlBasePath = $sqlBasePath;
        } else {
            $this->args->printError("SQL base path not provided. Use --sqlPath option to specify the path.");
            $this->args->writeHelp();
            exit(1);
        }

        $this->migrationType = $this->args->getMigrationType();

        /**
         * Following things are not needed for migration, so we can skip them to speed up the process.
         */
        // $this->beginModules();
        // $this->onApplicationReady();

        if ($this->isOpenSearchMigration()) {
            $this->executeOpenSearchMigrations();
        } else {
            $this->executeSqlMigrations();
        }

        $this->exit(0);
    }

    #[\Override]
    protected function initModules() {
        // No modules needed for SQL migration
    }

    #[\Override]
    protected function loadModules() {
        // No modules needed for SQL migration
    }

    #[\Override]
    protected function beginModules(): void {
        // No modules needed for SQL migration
    }

    #[\Override]
    protected function onApplicationReady(): void {
        // No onApplicationReady events needed for SQL migration
    }

    private function isOpenSearchMigration(): bool {
        return $this->migrationType !== null
            && strtolower(trim($this->migrationType)) === self::MIGRATION_TYPE_OPENSEARCH;
    }

    private function executeSqlMigrations(): void {
        $sqlMigrationService = new SqlMigrationService(
            $this->applicationContext,
            $this->sqlBasePath,
            $this->configDir
        );
        $sqlMigrationService->executeMigrations();
    }

    private function executeOpenSearchMigrations(): void {
        $osMigrationService = new OpenSearchMigrationService(
            $this->applicationContext,
            $this->sqlBasePath,
            $this->configDir
        );
        $osMigrationService->executeMigrations();
    }
}
