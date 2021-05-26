<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\HttpHeaders;
use examples\MyApp\data\Product;
use examples\MyApp\service\AsyncService;

#[RestController]
class TestController {

    #[Autowired]
    protected AsyncService $asyncService;

    #[RequestMapping(path: "/test/body", method: [RequestMethod::POST])]
    public function test(
        #[RequestBody] Product $product
    ): string {
        return 'Product: name = ' . $product->getName() . ', Price = ' . $product->getPrice();
    }

    #[GetMapping(path: "/test/header")]
    public function testHeader(
        #[RequestParam(name: HttpHeaders::USER_AGENT, source: "header")] string $userAgent
    ): string {
        return 'UserAgent = ' . json_encode($userAgent);
    }

    #[GetMapping(path: "/test/cookie")]
    public function testCookie(
        #[RequestParam(name: 'counter', required: false, defaultValue: 1, source: "cookie")]
        int $counter
    ): string {
        return 'Cookie = ' . $counter;
    }

    #[PostMapping(path: "/test/bodyString")]
    public function testBodyString(
        #[RequestBody] string $body
    ): string {
        return 'Request Body: ' . $body;
    }

    #[GetMapping(path: "/test/async")]
    public function testAsync(): string {
        $id = time();
        $str = str_repeat("-=", 700);
        $this->asyncService->lazyWork($id, 'Suvera ' . $str);
        return 'Async Initiated by PID: ' . getmypid() . "  $id \n";
    }

    #[GetMapping(path: "/admin/show")]
    public function adminShow(): string {
        return "ADMIN";
    }
}