<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

use dev\winterframework\io\queue\QueueServer;

if (isset($_SERVER['WINTER_VENDOR_HOME']) && $_SERVER['WINTER_VENDOR_HOME']) {
    $dir = $_SERVER['WINTER_VENDOR_HOME'];
} else {
    $dir = dirname(dirname(dirname(__DIR__)));
}
require_once($dir . '/autoload.php');

$stdin = fopen("php://stdin", 'r');
$lines = [];
while (!feof($stdin)) {
    $lines[] = stream_get_line($stdin, 1024, "\n");
}
fclose($stdin);

$port = isset($lines[0]) ? intval($lines[0]) : 0;
if (!is_int($port) || !$port || $port < 1 || $port > 65535) {
    echo "Port must be a number between 1 - 65535\n";
    exit(1);
}

$address = (isset($lines[1]) && $lines[1]) ? $lines[1] : '127.0.0.1';
$token = (isset($lines[2])) ? $lines[2] : '';
$pgId = (isset($lines[3])) ? $lines[3] : 0;
if ($pgId) {
    posix_setpgid(getmypid(), intval($pgId));
}

$kv = new QueueServer(
    $token,
    []
);

$kv->start($port, $address);
