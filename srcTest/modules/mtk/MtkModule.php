<?php
declare(strict_types=1);

namespace test\winterframework\modules\mtk;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\stereotype\Module;

#[Module]
class MtkModule implements WinterModule {
    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        // TODO: Implement init() method.
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        // TODO: Implement begin() method.
    }

}