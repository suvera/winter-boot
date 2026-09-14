<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\web\RequestMapping;
use TypeError;
use winterBootTests\Support\TestCase;

#[RestController]
#[Service]
class RestControllerServiceCombo {
}

#[RestController]
class PlainRestController {
}

#[RestController]
#[RequestMapping(path: 'combo')]
class RestControllerMappingCombo {
}

/**
 * #[RestController] must not be combined with other bean stereotypes
 * (Service, Component, ...) on the same class.
 */
final class RestControllerExclusivityTest extends TestCase {

    public function testRestControllerRejectsServiceCombo(): void {
        $attr = new RestController();
        $this->assertThrows(
            TypeError::class,
            function () use ($attr): void {
                $attr->init(new RefKlass(RestControllerServiceCombo::class));
            }
        );
    }

    public function testRestControllerAloneIsAccepted(): void {
        $attr = new RestController();
        $attr->init(new RefKlass(PlainRestController::class));
        $this->assertTrue(true);
    }

    public function testRestControllerWithRequestMappingIsAccepted(): void {
        $attr = new RestController();
        $attr->init(new RefKlass(RestControllerMappingCombo::class));
        $this->assertTrue(true);
    }
}
