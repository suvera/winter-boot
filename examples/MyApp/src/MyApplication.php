<?php
declare(strict_types=1);

namespace examples\MyApp;

use dev\winterframework\core\app\WinterWebApplication;
use dev\winterframework\stereotype\WinterBootApplication;

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/../config"],
    scanNamespaces: [
        ['examples\\MyApp', __DIR__ . '']
    ]
)]
class MyApplication {

    public static function main(): void {
        (new WinterWebApplication())->run(self::class);
    }

}