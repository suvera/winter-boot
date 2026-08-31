<?php

declare(strict_types=1);

use dev\winterframework\core\app\WinterMigrationApplication;
use dev\winterframework\stereotype\WinterBootApplication;

require_once(dirname(__DIR__) . '/vendor/autoload.php');

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/../config"],
    scanNamespaces: [
        ['dev\\winterframework', __DIR__ . "/../src"]
    ]
)]
class MigrationApplication {

    public static function main(): void {
        $winterApp = new WinterMigrationApplication();
        $winterApp->run(self::class);
    }
}

MigrationApplication::main();
