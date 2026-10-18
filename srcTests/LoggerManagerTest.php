<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\util\log\LoggerManager;
use dev\winterframework\util\log\LogWrapper;
use dev\winterframework\util\log\WinterConsoleLogFormatter;
use dev\winterframework\util\log\Wlf4p;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessIdProcessor;
use winterBootTests\Support\TestCase;

final class LmQuietLogger {
    use Wlf4p;
}

final class LmSilentLogger {
    use Wlf4p;
}

final class LmDefaultLogger {
    use Wlf4p;
}

/**
 * LoggerManager::buildInstance() runs on every application boot: it loads the
 * logger.yml config through monolog-cascade and resolves `custom_level` names
 * to Monolog level values for Wlf4p's per-class filtering.
 */
final class LoggerManagerTest extends TestCase {

    private function withConfig(callable $fn): void {
        LoggerManager::getLogger();
        $ref = new \ReflectionProperty(LoggerManager::class, 'instance');
        $instance = $ref->getValue();
        $logRef = new \ReflectionProperty(LoggerManager::class, 'logger');
        $savedLogger = $logRef->getValue($instance);
        $saved = [LogWrapper::$LOG_LEVELS, LogWrapper::$LOG_LEVEL_NAMES, LogWrapper::$LOG_CACHED_LEVELS];

        LoggerManager::buildInstance([
            'formatters' => [
                'fmt' => [
                    'class' => WinterConsoleLogFormatter::class,
                    'format' => "[%extra.process_id%] [%level_name%] - %message%\n",
                ],
            ],
            'handlers' => [
                'capture' => [
                    'class' => TestHandler::class,
                    'level' => 'DEBUG',
                    'formatter' => 'fmt',
                    'processors' => ['pid'],
                ],
            ],
            'processors' => [
                'pid' => ['class' => ProcessIdProcessor::class],
            ],
            'loggers' => [
                'lm_test_logger' => [
                    'handlers' => ['capture'],
                    'custom_level' => [
                        LmQuietLogger::class => 'warning',
                        LmSilentLogger::class => 'NONE',
                    ],
                ],
            ],
        ]);

        try {
            $fn(LoggerManager::getLogger());
        } finally {
            $logRef->setValue($instance, $savedLogger);
            LogWrapper::$LOGGER = $savedLogger;
            [LogWrapper::$LOG_LEVELS, LogWrapper::$LOG_LEVEL_NAMES, LogWrapper::$LOG_CACHED_LEVELS] = $saved;
        }
    }

    public function testBuildInstanceResolvesLevelNames(): void {
        $this->withConfig(function (Logger $logger) {
            $this->assertSame('lm_test_logger', $logger->getName());
            $this->assertSame(100, LogWrapper::$LOG_LEVEL_NAMES['DEBUG'] ?? null);
            $this->assertSame(300, LogWrapper::$LOG_LEVEL_NAMES['WARNING'] ?? null);
            $this->assertSame(600, LogWrapper::$LOG_LEVEL_NAMES['EMERGENCY'] ?? null);
            $this->assertSame(300, LogWrapper::$LOG_CACHED_LEVELS[LmQuietLogger::class] ?? null);
            $this->assertSame(PHP_INT_MAX, LogWrapper::$LOG_CACHED_LEVELS[LmSilentLogger::class] ?? null);
        });
    }

    public function testCustomLevelFiltersPerClass(): void {
        $this->withConfig(function (Logger $logger) {
            LmQuietLogger::logInfo('quiet info');
            LmQuietLogger::logWarning('quiet warning');
            LmSilentLogger::logError('silent error');
            LmDefaultLogger::logDebug('default debug');

            /** @var TestHandler $handler */
            $handler = $logger->getHandlers()[0];
            $messages = array_map(fn($r) => $r['formatted'], $handler->getRecords());

            $this->assertSame(2, count($messages), json_encode($messages));
            $this->assertTrue(str_contains($messages[0], '[WARNING] -') && str_contains($messages[0], 'quiet warning'),
                json_encode($messages));
            $this->assertTrue(str_contains($messages[1], '[DEBUG] -') && str_contains($messages[1], 'default debug'),
                json_encode($messages));
            $this->assertTrue(str_contains($messages[0], '[' . getmypid() . ']'), json_encode($messages));
        });
    }
}
