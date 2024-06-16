<?php

declare(strict_types=1);

namespace dev\winterframework\util\log;

use Cascade\Cascade;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonoLogger;

final class LoggerManager {
    private static LoggerManager $instance;
    private MonoLogger $logger;

    private function __construct() {
        $this->logger = new MonoLogger('Winter');
    }

    private static function getInstance(): LoggerManager {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function getLogger(): MonoLogger {
        self::getInstance();
        LogWrapper::$LOGGER = self::$instance->logger;
        return self::$instance->logger;
    }

    /**
     * @param array $data -  YAML File Path (or) Array of Configuration
     */
    public static function buildInstance(array $data): void {
        self::getInstance();

        $defaultName = null;
        $customLevels = [];
        if (isset($data['loggers']) && is_array($data['loggers'])) {
            foreach ($data['loggers'] as $loggerName => $loggerOptions) {
                $defaultName = $loggerName;
                $customLevels = $loggerOptions['custom_level'] ?? [];
                break;
            }
        }

        Cascade::fileConfig($data);

        self::$instance->logger = Cascade::getLogger($defaultName);
        self::setLogger(self::$instance->logger, $customLevels);
    }

    public function addHandler(HandlerInterface $handler): void {
        $this->logger->pushHandler($handler);
    }

    public static function setLogger(
        MonoLogger $logger,
        array $log_levels = []
    ) {
        LogWrapper::$LOGGER = $logger;
        LogWrapper::$LOG_LEVELS = $log_levels;
        LogWrapper::$LOG_LEVEL_NAMES = [];
        LogWrapper::$LOG_CACHED_LEVELS = [];

        if (!LogWrapper::$LOG_LEVEL_NAMES) {
            LogWrapper::$LOG_LEVEL_NAMES = LoggerManager::getLogger()->getLevels();
        }

        foreach (LogWrapper::$LOG_LEVELS as $clsPath => $clsLevel) {
            $clsLevel = strtoupper($clsLevel);
            if ($clsLevel === 'NONE') {
                LogWrapper::$LOG_CACHED_LEVELS[$clsPath] = PHP_INT_MAX;
            } else {
                LogWrapper::$LOG_CACHED_LEVELS[$clsPath] = LogWrapper::$LOG_LEVEL_NAMES[$clsLevel] ?? 0;
            }
        }
    }
}
