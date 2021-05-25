<?php
declare(strict_types=1);

namespace examples\MyApp;

use dev\winterframework\core\app\WinterWebSwooleApplication;
use dev\winterframework\stereotype\task\EnableAsync;
use dev\winterframework\stereotype\WinterBootApplication;

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/../config"],
    scanNamespaces: [
        ['examples\\MyApp', __DIR__ . '']
    ]
)]
#[EnableAsync]
class MySwooleApplication {

    public static function main(): void {
        $winterApp = new WinterWebSwooleApplication();
        $winterApp->run(self::class);
    }

}