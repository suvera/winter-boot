<?php
declare(strict_types=1);

namespace test\winterframework\stereotype\classes;

use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\RequestMapping;

#[RestController]
#[RequestMapping(path: "/users/")]
class RequestMappingMethod03 {

    #[RequestMapping(path: "/create", method: ["GET", "POST"])]
    public function create() {
    }
}