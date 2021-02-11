<?php
declare(strict_types=1);

namespace test\winterframework\core\context\classes\t0001;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class T0006Service {

    private T0005Service $value;

    #[Autowired]
    private ApplicationContext $appCtx;

    public function __construct() {
        $this->value = $this->appCtx->beanByClass(T0005Service::class);
    }
}