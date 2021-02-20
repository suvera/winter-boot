<?php
declare(strict_types=1);

namespace test\winterframework\lib;

use dev\winterframework\core\app\WinterWebApplication;

class WinterTestWebApplication extends WinterWebApplication {

    protected function serverRequest(): void {
        // do nothing
    }

}