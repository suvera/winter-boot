<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\stereotype\Configuration;
use winterBootTests\fixtures\webapp\ws\FixtureSocketContext;

#[Configuration(name: 'webMvcConfigurer')]
class FixtureWebConfig implements WebMvcConfigurer {

    public function addInterceptors(InterceptorRegistry $registry): void {
        $registry->addInterceptor(new FixtureAuthInterceptor(), '^\/secure', '^\/stream', '^\/ws\/secure');
        if (class_exists(FixtureSocketContext::class)) {
            $registry->addContextPropagator(new FixtureSocketContext());
        }
    }
}
