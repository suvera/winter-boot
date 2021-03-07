<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestMapping;
use examples\MyApp\data\Product;

#[RestController]
class TestController {

    #[RequestMapping(path: "/test/body", method: [RequestMethod::POST])]
    public function test(
        #[RequestBody] Product $product
    ): string {
        return 'Product: name = ' . $product->getName() . ', Price = ' . $product->getPrice();
    }

}