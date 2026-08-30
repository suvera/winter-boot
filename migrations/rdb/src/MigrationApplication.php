<?php

namespace dev\winterframework\migrations\rdb;

use dev\winterframework\core\app\WinterCliApplication;
use dev\winterframework\stereotype\WinterBootApplication;

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/config"],
    scanNamespaces: [
        ['dev\\winterframework\\migrations\rdb', __DIR__ . '/src']
    ]
)]

class MigrationApplication {

    public static function main(): void {
        $winterApp = new WinterCliApplication();
        $winterApp->run(self::class);
    }
}