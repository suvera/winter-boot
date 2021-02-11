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
        Wlf4p::$LOGGER = self::$instance->logger;
        return self::$instance->logger;
    }

    /**
     * @param array $data -  YAML File Path (or) Array of Configuration
     */
    public static function buildInstance(array $data): void {
        self::getInstance();

        $defaultName = null;
        if (isset($data['loggers']) && is_array($data['loggers'])) {
            foreach ($data['loggers'] as $loggerName => $loggerOptions) {
                $defaultName = $loggerName;
                break;
            }
        }

        Cascade::fileConfig($data);

        self::$instance->logger = Cascade::getLogger($defaultName);
        Wlf4p::$LOGGER = self::$instance->logger;
    }

    public function addHandler(HandlerInterface $handler): void {
        $this->logger->pushHandler($handler);
    }

}