<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\stereotype\WinterBootApplication;

/**
 * End-to-end fixture application booted by srcTests/swoole/support/FixtureServer
 * in a subprocess (see boot.php). The config directory is generated per test
 * and passed with -c, so configDirectory below is only a fallback.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__],
    scanNamespaces: [['winterBootTests\\fixtures\\webapp', __DIR__]]
)]
class WebFixtureApp {
}
