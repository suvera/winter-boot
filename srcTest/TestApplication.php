<?php
declare(strict_types=1);

namespace test\winterframework;

use dev\winterframework\core\app\WinterWebApplication;
use dev\winterframework\stereotype\WinterBootApplication;

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/config"],
    scanNamespaces: [
        ['test\\winterframework\\noApp', __DIR__ . '/noApp']
    ]
)]
class TestApplication {
    public static function main(): void {
        (new WinterWebApplication())->run(self::class);
    }
}