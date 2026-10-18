<?php

declare(strict_types=1);

namespace dev\winterframework\util\log;

use dev\winterframework\util\Debug;
use Monolog\Level;
use Throwable;

trait Wlf4p {

    public static function logEmergency(string $message, array $context = []) {
        self::logLog(Level::Emergency->value, $message, $context);
    }

    public static function logAlert(string $message, array $context = []) {
        self::logLog(Level::Alert->value, $message, $context);
    }

    public static function logCritical(string $message, array $context = []) {
        self::logLog(Level::Critical->value, $message, $context);
    }

    public static function logException(Throwable $ex, string $message = '', array $context = []) {
        $message = $message . Debug::exceptionBacktrace($ex);
        self::logLog(Level::Error->value, $message, $context);
    }

    public static function logEx(Throwable $e, string $message = '', array $context = []) {
        $message = $message . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage()
            . ', at line: ' . $e->getFile() . ', at line: ' . $e->getLine();
        self::logLog(Level::Error->value, $message, $context);
    }

    public static function logError(string $message, array $context = []) {
        self::logLog(Level::Error->value, $message, $context);
    }

    public static function logWarning(string $message, array $context = []) {
        self::logLog(Level::Warning->value, $message, $context);
    }

    public static function logNotice(string $message, array $context = []) {
        self::logLog(Level::Notice->value, $message, $context);
    }

    public static function logInfo(string $message, array $context = []) {
        self::logLog(Level::Info->value, $message, $context);
    }

    public static function logDebug(string $message, array $context = []) {
        self::logLog(Level::Debug->value, $message, $context);
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
