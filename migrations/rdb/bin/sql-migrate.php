<?php
declare(strict_types=1);
use dev\winterframework\migrations\rdb\MigrationApplication;

require_once(dirname(__DIR__) . '/vendor/autoload.php');

MigrationApplication::main();