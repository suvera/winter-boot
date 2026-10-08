<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\stereotype\util\ComponentName;
use dev\winterframework\core\aop\ex\AopException;
use Throwable;

trait AopContextExecute {
    protected static function executeInlineCode(
        string $__c_o_d_e,
        object $target,
        array $__namedArgs
    ): mixed {
        try {
            // Native evaluation: variables bind inside the extension, so no
            // dynamic code evaluation remains in PHP code.
            return winter_boot_exec_inline($__c_o_d_e, $__namedArgs);
        } catch (Throwable $e) {
            throw new AopException(
                sprintf("Error executing AOP inline code in target [%s]: %s, [CODE]: %s", $target::class, $e->getMessage(), $__c_o_d_e),
                $e->getCode(),
                $e
            );
        }
    }

    protected static function buildNameByContext(
        ComponentName $name,
        AopContext $ctx,
        object $target,
        array $args
    ): string {

        $value = $name->getName();
        if (!$name->hasArguments() && !$name->hasProperties()) {
            return $value;
        }

        // Placeholder pairs in evaluation order (arguments first, then
        // properties): a single sequential substitution, so an evaluated
        // value that itself contains a later placeholder re-expands exactly
        // like the former back-to-back str_replace() calls did.
        $pairs = [];
        if ($name->hasArguments()) {
            $namedArgs = [];
            foreach ($ctx->getMethod()->getParameters() as $methodParam) {
                $pos = $methodParam->getPosition();
                $namedArgs[$methodParam->getName()] = isset($args[$pos]) ? $args[$pos] : null;
            }

            foreach ($name->getArguments() as $tpl => $code) {
                $pairs[$tpl] = self::executeInlineCode($code, $target, $namedArgs);
            }
        }

        if ($name->hasProperties()) {
            $appCtx = $ctx->getApplicationContext();
            foreach ($name->getProperties() as $tpl => $prop) {
                $pairs[$tpl] = $appCtx->getPropertyStr($prop, '');
            }
        }

        try {
            // Native substitution (bounded against runaway expansion); the
            // winter_boot extension is mandatory since 2.1.0, so there is no
            // PHP fallback to drift from it.
            return winter_boot_expand_template($value, $pairs);
        } catch (Throwable $e) {
            throw new AopException(
                sprintf("Error expanding AOP name in target [%s]: %s, [NAME]: %s", $target::class, $e->getMessage(), $value),
                $e->getCode(),
                $e
            );
        }
    }
}
