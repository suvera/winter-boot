#!/usr/bin/env php
<?php

declare(strict_types=1);

// Zero-dependency unit test runner. Usage: php srcTests/run.php

define('WB_ROOT', dirname(__DIR__));

require WB_ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'winterBootTests\\';
    if (str_starts_with($class, $prefix)) {
        $file = WB_ROOT . '/srcTests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$testFiles = glob(WB_ROOT . '/srcTests/*Test.php');
$pass = 0;
$fail = 0;
foreach ($testFiles as $file) {
    require_once $file;
    $class = 'winterBootTests\\' . basename($file, '.php');
    if (!class_exists($class)) {
        echo "SKIP $class (class not found)\n";
        continue;
    }
    $test = new $class();
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $label = $class . '::' . $method;
        try {
            $test->$method();
            $pass++;
            echo "ok   $label\n";
        } catch (\Throwable $ex) {
            $fail++;
            echo "FAIL $label\n";
            echo '     ' . get_class($ex) . ': ' . $ex->getMessage() . "\n";
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
