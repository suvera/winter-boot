<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\stereotype\Component;

trait ModuleTrait {

    protected function addBeanComponent(
        ApplicationContext $ctx,
        ApplicationContextData $ctxData,
        string $cls
    ) {
        if ($ctx->hasBeanByClass($cls)) {
            return;
        }

        $clsRes = $ctx->addClass($cls);

        $cmp = new Component();
        $cmp->init($clsRes->getClass());

        $ctxData->getBeanProvider()->addProviderClassAs($clsRes, [$cmp]);
    }
}