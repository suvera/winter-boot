<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\core\web\SwooleDispatcherServlet;

class WinterWebSwooleContext extends WinterWebContext {

    protected function initDispatcherServlet() {

        $this->dispatcherServlet = new SwooleDispatcherServlet(
            $this->requestMapping,
            $this->ctxData,
            $this->appCtx
        );
    }

}