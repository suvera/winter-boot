<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp\ws;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\websocket\WebSocketContextHook;
use dev\winterframework\web\websocket\WebSocketSession;
use winterBootTests\fixtures\webapp\FixtureContext;

/** What SocMon does with its TenantContext: capture at the handshake, restore per call. */
class FixtureSocketContext implements WebSocketContextHook {

    public function capture(WebSocketSession $session, HttpRequest $handshake): void {
        $session->setAttribute('user', FixtureContext::user());
    }

    public function restore(WebSocketSession $session): void {
        FixtureContext::set($session->getAttribute('user'));
    }

    public function clear(WebSocketSession $session): void {
        FixtureContext::clear();
    }
}
