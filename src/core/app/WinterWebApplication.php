<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\WinterWebContext;

class WinterWebApplication extends WinterApplicationRunner implements WinterApplication {

    protected WinterWebContext $webContext;

    protected function runBootApp(): void {
        $this->webContext = new WinterWebContext(
            $this->appCtxData,
            $this->applicationContext
        );

        $this->beginModules();
        $this->onApplicationReady();
        $this->serveRequest();
    }

    protected function serveRequest(): void {
        if (php_sapi_name() != 'cli') {
            $this->webContext->getDispatcher()->dispatch();
        }
    }

}