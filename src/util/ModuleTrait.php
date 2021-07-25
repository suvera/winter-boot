<?php
/** @noinspection PhpUnusedParameterInspection */
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\util;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\BeansDependencyException;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\Module;

trait ModuleTrait {

    protected function addBeanComponent(
        ApplicationContext $ctx,
        ApplicationContextData $ctxData,
        string $cls,
        string $name = ''
    ) {
        $this->addValidateBean($ctx, $cls, $name);

        $clsRes = $ctx->addClass($cls);

        $cmp = new Component($name);
        $cmp->init($clsRes->getClass());

        $ctxData->getBeanProvider()->addProviderClassAs($clsRes, [$cmp]);
    }

    private function addValidateBean(
        ApplicationContext $ctx,
        string $cls,
        string $name = ''
    ) {
        if ($ctx->hasBeanByClass($cls)) {
            return;
        }

        if ($name && $ctx->hasBeanByName($name)) {
            throw new BeansDependencyException("Duplicate bean name found, Bean with name '$name' already exist");
        }
    }

    protected function addBeanConfiguration(
        ApplicationContext $ctx,
        ApplicationContextData $ctxData,
        string $cls,
        string $name = ''
    ) {

        $this->addValidateBean($ctx, $cls, $name);
        $clsRes = $ctx->addClass($cls);

        $cmp = new Configuration($name);
        $cmp->init($clsRes->getClass());

        $ctxData->getBeanProvider()->addProviderClassAs($clsRes, [$cmp]);
    }

    protected function retrieveConfiguration(
        ApplicationContext $ctx,
        ApplicationContextData $ctxData,
        Module $module
    ): array {

        return ConfigFileLoader::retrieveConfiguration($ctxData, $module);
    }
}