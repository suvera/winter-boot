<?php
/** @noinspection DuplicatedCode */
declare(strict_types=1);

use dev\winterframework\io\queue\QueueServer;

$dir = dirname(__DIR__);
require_once($dir . '/src/io/queue/QueueServer.php');
require_once($dir . '/src/io/queue/QueueCommand.php');
require_once($dir . '/src/io/queue/QueueRequest.php');
require_once($dir . '/src/io/queue/QueueResponse.php');
require_once($dir . '/src/io/queue/QueueException.php');
require_once($dir . '/src/type/IntegerMinHeap.php');

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

$kv = new QueueServer([]);

$kv->start($port, $address);