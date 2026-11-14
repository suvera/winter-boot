<?php

declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\support\StereoTypeValidations;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\stereotype\Module;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\web\websocket\WebSocketHandler;

/**
 * Serves WebSocket connections on a path:
 *
 * ```php
 * #[WebSocketController(path: '/ws/assistant')]
 * class AssistantSocket implements WebSocketHandler { ... }
 * ```
 *
 * The class becomes a bean (autowiring works as in a #[Service]). Its
 * presence turns the HTTP server into a WebSocket server on the same port;
 * the upgrade request passes the application's HandlerInterceptors first.
 * The path is relative to server.context-path, like a route.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class WebSocketController implements StereoType {
    use StereoTypeValidations;

    private RefKlass $refOwner;

    public function __construct(
        public string $path,
        public string $name = ''
    ) {
    }

    public function getRefOwner(): RefKlass {
        return $this->refOwner;
    }

    public function init(object $ref): void {
        /** @var RefKlass $ref */
        TypeAssert::typeOf($ref, RefKlass::class);
        $this->cannotBeAbstractClass($ref, 'WebSocketController');
        $this->mustImplementOneOf($ref, 'WebSocketController', WebSocketHandler::class);
        $this->cannotBeCombinedWith(
            $ref,
            'WebSocketController',
            'Bean',
            [
                Service::class,
                Component::class,
                Configuration::class,
                Module::class,
                RestController::class,
                WebSocketController::class,
            ]
        );
        $this->refOwner = $ref;
    }
}
