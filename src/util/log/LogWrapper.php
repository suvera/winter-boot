<?php
declare(strict_types=1);

namespace dev\winterframework\util\log;

use Monolog\Logger as MonoLogger;

class LogWrapper {
    public static MonoLogger $LOGGER;
    public static array $LOG_LEVELS = [];
    public static array $LOG_LEVEL_NAMES = [];
    public static array $LOG_CACHED_LEVELS = [];
}