<?php
declare(strict_types=1);

namespace dev\winterframework\util\log;

use dev\winterframework\util\Debug;
use Monolog\Logger as MonoLogger;
use Throwable;

trait Wlf4p {
    public static MonoLogger $LOGGER;

    public static function logEmergency(string $message, array $context = []) {
        self::logLog(MonoLogger::EMERGENCY, $message, $context);
    }

    public static function logAlert(string $message, array $context = []) {
        self::logLog(MonoLogger::ALERT, $message, $context);
    }

    public static function logCritical(string $message, array $context = []) {
        self::logLog(MonoLogger::CRITICAL, $message, $context);
    }

    public static function logException(Throwable $ex, string $message = '', array $context = []) {
        $message = $message . Debug::exceptionBacktrace($ex);
        self::logLog(MonoLogger::ERROR, $message, $context);
    }

    public static function logError(string $message, array $context = []) {
        self::logLog(MonoLogger::ERROR, $message, $context);
    }

    public static function logWarning(string $message, array $context = []) {
        self::logLog(MonoLogger::WARNING, $message, $context);
    }

    public static function logNotice(string $message, array $context = []) {
        self::logLog(MonoLogger::NOTICE, $message, $context);
    }

    public static function logInfo(string $message, array $context = []) {
        self::logLog(MonoLogger::INFO, $message, $context);
    }

    public static function logDebug(string $message, array $context = []) {
        self::logLog(MonoLogger::DEBUG, $message, $context);
    }

    public static function logLog(int $level, string $message, array $context = []) {
        $cls = preg_replace('/([a-zA-Z_])\w*\\\\/', '${1}.', static::class) . ' ';

        LoggerManager::getLogger()->addRecord($level, $cls . $message, $context);
    }

}