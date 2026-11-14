<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;

#[RestController]
class HelloController {

    #[GetMapping(path: '/hello')]
    public function hello(): string {
        return 'hello';
    }

    #[GetMapping(path: '/secure/whoami')]
    public function whoami(): array {
        return ['user' => FixtureContext::user()];
    }

    /** Interceptor hook counts, read by the tests (fixture runs one worker). */
    #[GetMapping(path: '/calls')]
    public function calls(): array {
        return FixtureAuthInterceptor::$calls;
    }
}
