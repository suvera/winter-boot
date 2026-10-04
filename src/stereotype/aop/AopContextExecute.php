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

        if ($name->hasArguments()) {
            $namedArgs = [];
            foreach ($ctx->getMethod()->getParameters() as $methodParam) {
                $pos = $methodParam->getPosition();
                $namedArgs[$methodParam->getName()] = isset($args[$pos]) ? $args[$pos] : null;
            }
            
            $search = [];
            $replace = [];
            foreach ($name->getArguments() as $tpl => $code) {
                $search[] = $tpl;
                $replace[] = self::executeInlineCode($code, $target, $namedArgs);
            }

            $value = str_replace($search, $replace, $value);
        }

        if ($name->hasProperties()) {
            $appCtx = $ctx->getApplicationContext();
            $search = [];
            $replace = [];
            foreach ($name->getProperties() as $tpl => $prop) {
                $search[] = $tpl;
                $replace[] = $appCtx->getPropertyStr($prop, '');
            }

            $value = str_replace($search, $replace, $value);
        }

        return $value;
    }
}
