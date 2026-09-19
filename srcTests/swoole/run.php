<?php

declare(strict_types=1);

// Swoole-runtime test runner. Usage:
//   php srcTests/swoole/run.php [TestClass]
// Each test METHOD runs in its own PHP subprocess: the Swoole extension can
// segfault at process shutdown once PDO handles have lived inside coroutines
// (environmental: reproducible with zero library code), and a shared-process
// runner would die before printing later results. Subprocess isolation keeps
// every result visible; a crash is reported as an infra failure, distinct
// from a test failure.

define('WB_SWOOLE_ROOT', dirname(__DIR__, 2));

require WB_SWOOLE_ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'winterBootTests\\';
    if (str_starts_with($class, $prefix)) {
        $file = WB_SWOOLE_ROOT . '/srcTests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function listTestClasses(): array {
    $classes = [];
    foreach (glob(WB_SWOOLE_ROOT . '/srcTests/swoole/*Test.php') as $file) {
        $class = 'winterBootTests\\swoole\\' . basename($file, '.php');
        require_once $file;
        if (class_exists($class)) {
            $classes[] = $class;
        }
    }
    return $classes;
}

function testMethods(string $class): array {
    $methods = [];
    foreach (get_class_methods($class) as $method) {
        if (str_starts_with($method, 'test')) {
            $methods[] = $method;
        }
    }
    return $methods;
}

if (isset($argv[1]) && $argv[1] === '--method') {
    // Child mode: run exactly one method, print one result line.
    [, , $class, $method] = $argv;
    require_once WB_SWOOLE_ROOT . '/srcTests/swoole/' . substr($class, strrpos($class, '\\') + 1) . '.php';
    try {
        (new $class())->$method();
        echo "ok   $class::$method\n";
    } catch (\Throwable $ex) {
        echo 'FAIL ' . $class . '::' . $method . ' ' . get_class($ex) . ': ' . $ex->getMessage() . "\n";
    }
    return;
}

$only = $argv[1] ?? null;
$pass = 0;
$fail = 0;
$crashed = [];
foreach (listTestClasses() as $class) {
    if ($only !== null && !str_contains($class, $only)) {
        continue;
    }
    foreach (testMethods($class) as $method) {
        $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__)
            . ' --method ' . escapeshellarg($class) . ' ' . escapeshellarg($method);
        $output = [];
        exec($cmd . ' 2>&1', $output);
        $result = null;
        foreach ($output as $outLine) {
            if (str_starts_with($outLine, 'ok   ') || str_starts_with($outLine, 'FAIL ')) {
                $result = $outLine;
            }
        }
        $died = false;
        foreach ($output as $outLine) {
            if (str_contains($outLine, 'Segmentation fault') || str_contains($outLine, 'core dumped')) {
                $died = true;
            }
        }
        if ($result !== null) {
            echo $result . "\n";
            if (str_starts_with($result, 'ok   ')) {
                $pass++;
            } else {
                $fail++;
            }
        }
        if ($died) {
            $crashed[] = "$class::$method (result above; process segfaulted at shutdown)";
        } elseif ($result === null) {
            $crashed[] = "$class::$method (process died with no result)";
        }
    }
}

echo "\n$pass passed, $fail failed";
if (!empty($crashed)) {
    echo ', ' . count($crashed) . ' crashed (no result line):';
    foreach ($crashed as $c) {
        echo "\n     $c";
    }
}
echo "\n";
exit($fail > 0 || !empty($crashed) ? 1 : 0);
