<?php
declare(strict_types=1);

namespace dev\winterframework\telemetry;

use dev\winterframework\core\context\ApplicationContext;

class WinterTelemetry {
    private static ?ApplicationContext $applicationContext = null;

    public static function setApplicationContext(ApplicationContext $ctx): void {
        self::$applicationContext = $ctx;
    }

    public static function getApplicationContext(): ?ApplicationContext {
        return self::$applicationContext;
    }

    public static function isEnabled(): bool {
        if (self::$applicationContext !== null) {
            if (method_exists(self::$applicationContext, 'getPropertyBool')) {
                if (!self::$applicationContext->getPropertyBool('winter.telemetry.enabled', true)) {
                    return false;
                }
            }
            if (self::$applicationContext->hasModule(OpenTelemetryModule::class)) {
                return true;
            }
        }
        return extension_loaded('opentelemetry');
    }
}
