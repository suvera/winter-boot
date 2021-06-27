<?php
declare(strict_types=1);

use dev\winterframework\io\kv\KvServer;

$dir = dirname(__DIR__);
require_once($dir . '/src/io/kv/KvServer.php');
require_once($dir . '/src/io/kv/KvCommand.php');
require_once($dir . '/src/io/kv/KvRequest.php');
require_once($dir . '/src/io/kv/KvResponse.php');
require_once($dir . '/src/io/kv/KvException.php');

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

$kv = new KvServer([]);

$kv->start($port, $address);