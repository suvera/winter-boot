<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\stereotype\WinterBootApplication;

/**
 * The same fixture without its WebSocket handlers: must be a plain HTTP
 * server exactly as before WebSocket support existed.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__],
    scanNamespaces: [['winterBootTests\\fixtures\\webapp', __DIR__]],
    scanExcludeNamespaces: ['winterBootTests\\fixtures\\webapp\\ws']
)]
class PlainFixtureApp {
}
