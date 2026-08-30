<?php

namespace dev\winterframework\migrations\rdb;

use dev\winterframework\migrations\rdb\svc\SqlMigrationService;
use dev\winterframework\core\app\ApplicationReadyEvent;
use dev\winterframework\stereotype\OnApplicationReady;
use dev\winterframework\stereotype\Autowired;

#[OnApplicationReady]
class Executor implements ApplicationReadyEvent {

    #[Autowired]
    private SqlMigrationService $migrator;

    public function onApplicationReady(): void {
        $this->migrator->executeMigrations();
    }
}