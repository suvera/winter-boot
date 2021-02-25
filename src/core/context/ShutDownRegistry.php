<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\util\log\Wlf4p;
use SplObjectStorage;
use Throwable;

class ShutDownRegistry {
    use Wlf4p;

    private SplObjectStorage $beanProviders;

    public function __construct() {
        $this->beanProviders = new SplObjectStorage();
        register_shutdown_function([$this, 'onShutdown']);
    }

    public function onShutdown(): void {
        foreach ($this->beanProviders as $key) {
            /** @var BeanProvider $beanProvider */
            $beanProvider = $this->beanProviders[$key];
            if ($beanProvider->hasCached() && $beanProvider->hasDestroyMethod()) {
                $object = $beanProvider->getCached();
                $method = $beanProvider->getDestroyMethod();
                try {
                    $object->$method();
                } catch (Throwable $e) {
                    self::logException($e);
                }
            }
        }
    }

    public function registerBeanProvider(BeanProvider $beanProvider): void {
        $this->beanProviders->attach($beanProvider);
    }

    public function unRegisterBeanProvider(BeanProvider $beanProvider): void {
        $this->beanProviders->detach($beanProvider);
    }
}