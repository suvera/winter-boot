<?php
declare(strict_types=1);

namespace dev\winterframework\core\app;

final class WinterCliApplication extends WinterApplicationRunner implements WinterApplication {

    protected function runBootApp(): void {
        $this->beginModules();
        $this->onApplicationReady();
    }

}
