<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;

interface WinterModule {

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void;

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void;
}