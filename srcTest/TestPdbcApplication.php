<?php
declare(strict_types=1);

namespace test\winterframework;

use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;

#[WinterBootApplication(
    configDirectory: [__DIR__ . "/config"],
    scanNamespaces: [
        ['test\\winterframework\\noApp', __DIR__ . '/noApp']
    ]
)]
#[EnableTransactionManagement]
class TestPdbcApplication {

}