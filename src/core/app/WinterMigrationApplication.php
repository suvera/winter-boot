<?php

namespace dev\winterframework\core\app;

use dev\winterframework\migrations\SqlMigrationService;

final class WinterMigrationApplication  extends WinterApplicationRunner implements WinterApplication {

    protected string $sqlBasePath;
    protected string $configDir;

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

        /**
         * Following things are not needed for SQL migration, so we can skip them to speed up the process.
         */
        // $this->beginModules();
        // $this->onApplicationReady();

        $this->executeSqlMigrations();

        $this->exit(0);
    }

    private function executeSqlMigrations(): void {
        $sqlMigrationService = new SqlMigrationService($this->applicationContext, $this->sqlBasePath, $this->configDir);
        $sqlMigrationService->executeMigrations();
    }
}
