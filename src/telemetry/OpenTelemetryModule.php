<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\MissingExtensionException;
use dev\winterframework\stereotype\Module;
use dev\winterframework\util\ModuleTrait;
use dev\winterframework\telemetry\metrics\OpenTelemetryMetrics;
use dev\winterframework\telemetry\logs\OpenTelemetryLogs;

#[Module(title: 'OpenTelemetry')]
class OpenTelemetryModule implements WinterModule {
    use ModuleTrait;

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!extension_loaded('opentelemetry')) {
            throw new MissingExtensionException('OpenTelemetry PHP extension is required but not loaded.');
        }
        WinterTelemetry::setApplicationContext($ctx);
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        WinterTelemetry::setApplicationContext($ctx);
        $moduleDef = $ctx->getModule(static::class);
        $config = $this->retrieveConfiguration($ctx, $ctxData, $moduleDef);

        $telemetryConfig = $config['winter']['telemetry'] ?? ($config['telemetry'] ?? []);
        $serviceName = $telemetryConfig['serviceName'] ?? 'winter-application';

        $beanFactory = $ctxData->getBeanProvider();

        // Register OpenTelemetryMetrics bean
        $metrics = new OpenTelemetryMetrics();
        $beanFactory->registerInternalBean(
            $metrics,
            OpenTelemetryMetrics::class,
            !$ctx->hasBeanByClass(OpenTelemetryMetrics::class),
            'openTelemetryMetrics',
            true
        );

        // Register OpenTelemetryLogs bean
        $logs = new OpenTelemetryLogs();
        $beanFactory->registerInternalBean(
            $logs,
            OpenTelemetryLogs::class,
            !$ctx->hasBeanByClass(OpenTelemetryLogs::class),
            'openTelemetryLogs',
            true
        );
    }
}
