<?php

declare(strict_types=1);

// Boots a fixture app: php boot-webapp.php -c <configDir> [--app=<class>]
// (default srcTests/fixtures/webapp/WebFixtureApp).
// Started by srcTests/swoole/support/FixtureServer; not a test itself.

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'winterBootTests\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/srcTests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$app = winterBootTests\fixtures\webapp\WebFixtureApp::class;
foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--app=')) {
        $app = substr($arg, 6);
        unset($argv[$i], $_SERVER['argv'][$i]);
    }
}
$_SERVER['argv'] = array_values($_SERVER['argv']);
(new dev\winterframework\core\app\WinterWebSwooleApplication())->run($app);
