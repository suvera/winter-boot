<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry\logs;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;

class OpenTelemetryLogs {
    private ?LoggerInterface $logger = null;

    public function __construct(string $name = 'dev.winterframework.logs', string $version = '1.0.0') {
        if (class_exists(Globals::class) && method_exists(Globals::class, 'loggerProvider')) {
            $this->logger = Globals::loggerProvider()->getLogger($name, $version);
        }
    }

    public function emit(string $message, Severity $severity = Severity::INFO, array $attributes = []): void {
        if (!$this->logger) {
            return;
        }
        $logRecord = $this->logger->logRecordFactory()
            ->setText($message)
            ->setSeverityNumber($severity)
            ->setAttributes($attributes);

        $this->logger->emit($logRecord);
    }

    public function info(string $message, array $attributes = []): void {
        $this->emit($message, Severity::INFO, $attributes);
    }

    public function error(string $message, array $attributes = []): void {
        $this->emit($message, Severity::ERROR, $attributes);
    }

    public function warn(string $message, array $attributes = []): void {
        $this->emit($message, Severity::WARN, $attributes);
    }

    public function debug(string $message, array $attributes = []): void {
        $this->emit($message, Severity::DEBUG, $attributes);
    }
}
