<?php

declare(strict_types=1);

namespace dev\winterframework\util\log;

use dev\winterframework\util\Debug;
use Monolog\Logger as MonoLogger;
use Throwable;

trait Wlf4p {

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

    public static function logEx(Throwable $e, string $message = '', array $context = []) {
        $message = $message . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage()
            . ', at line: ' . $e->getFile() . ', at line: ' . $e->getLine();
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
        $clsName = static::class;

        if (!isset(LogWrapper::$LOG_CACHED_LEVELS[$clsName])) {
            LogWrapper::$LOG_CACHED_LEVELS[$clsName] = 0;

            $longest = 0;
            $lvl = 0;
            foreach (LogWrapper::$LOG_LEVELS as $clsPath => $clsLevel) {
                if (!str_starts_with($clsName, $clsPath)) {
                    continue;
                }
                if ($longest < strlen($clsPath)) {
                    $longest = strlen($clsPath);
                    $lvl = LogWrapper::$LOG_CACHED_LEVELS[$clsPath];
                }
            }
            LogWrapper::$LOG_CACHED_LEVELS[$clsName] = $lvl;
        }

        if ($level < LogWrapper::$LOG_CACHED_LEVELS[$clsName]) {
            return;
        }

        /** @noinspection RegExpSingleCharAlternation */
        $cls = preg_replace('/([a-zA-Z_])\w*(\\\\|_)/', '${1}.', $clsName) . ' ';

        LoggerManager::getLogger()->addRecord($level, $cls . $message, $context);
    }
}
