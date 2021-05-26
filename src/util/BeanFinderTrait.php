<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

trait BeanFinderTrait {
    use Wlf4p;

    protected function findBean(
        ApplicationContext $appCtx,
        string $beanName,
        string $interfaceCls,
        string $implCls
    ): mixed {
        $bean = null;
        try {
            $bean = $appCtx->beanByName($beanName);
            if (!($bean instanceof $interfaceCls)) {
                self::logInfo('Bean named "' . $beanName . '" does not implement ' . $interfaceCls);
                $bean = null;
            } else {
                return $bean;
            }
        } /** @noinspection PhpUnusedLocalVariableInspection */
        catch (Throwable $e) {
            // ignore this error
        }
        try {
            $bean = $appCtx->beanByClass($interfaceCls);
            if (!($bean instanceof $interfaceCls)) {
                self::logInfo('Bean object does not implement ' . $interfaceCls);
                $bean = null;
            } else {
                return $bean;
            }
        } catch (Throwable $e) {
            self::logInfo('No component/service/controller has implemented ' . $interfaceCls, [$e]);
        }

        if ($bean == null) {
            $bean = $appCtx->beanByClass($implCls);
        }
        return $bean;
    }
}