<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\enums\Allowable;

final class WinterInternalBeanAlias {
    private static array $internalClassAliases;

    public static function getClassAliases(): array {
        if (isset(self::$internalClassAliases)) {
            return self::$internalClassAliases;
        }

        return self::$internalClassAliases = [
            ErrorController::class => [
                'allowMultiple' => Allowable::SAFE_DISALLOW,
                'aliases' => []
            ],
            DefaultErrorController::class => [
                'allowMultiple' => Allowable::SAFE_DISALLOW,
                'aliases' => [ErrorController::class]
            ]
        ];
    }

}