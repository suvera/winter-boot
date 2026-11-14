<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp\ws;

use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestParam;

#[RestController]
class SocketProbeController {

    #[GetMapping(path: '/probe/ws-closed')]
    public function closed(#[RequestParam] string $user): array {
        return EchoSocket::$closed[$user] ?? ['code' => -1];
    }
}
