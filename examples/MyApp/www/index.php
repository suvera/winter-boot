<?php
declare(strict_types=1);

use examples\MyApp\MyApplication;

$GLOBALS['time'] = microtime(true);
require_once(__DIR__ . '/autoload.php');

MyApplication::main();
