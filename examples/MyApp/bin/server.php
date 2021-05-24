<?php
declare(strict_types=1);

use examples\MyApp\MySwooleApplication;

$GLOBALS['time'] = microtime(true);
require_once(dirname(__DIR__) . '/www/autoload.php');

MySwooleApplication::main();
