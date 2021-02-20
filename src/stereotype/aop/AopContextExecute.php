<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

use dev\winterframework\stereotype\util\ComponentName;

trait AopContextExecute {
    /** @noinspection PhpUnusedParameterInspection */
    protected static function executeInlineCode(
        string $__c_o_d_e,
        object $target,
        array $__namedArgs
    ): mixed {
        foreach ($__namedArgs as $name => $value) {
            if ($name == '__c_o_d_e' || $name == '__namedArgs') {
                continue;
            }

            $$name = $value;
        }

        return eval($__c_o_d_e);
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