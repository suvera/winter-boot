<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp\ws;

use dev\winterframework\core\web\RequestContextPropagator;
use dev\winterframework\web\http\HttpRequest;
use winterBootTests\fixtures\webapp\FixtureContext;

/** What SocMon does with its TenantContext: capture at the handshake, restore per call. */
class FixtureSocketContext implements RequestContextPropagator {

    public function capture(HttpRequest $request): mixed {
        return FixtureContext::user();
    }

    public function restore(mixed $snapshot): void {
        FixtureContext::set($snapshot);
    }

    public function clear(): void {
        FixtureContext::clear();
    }
}
