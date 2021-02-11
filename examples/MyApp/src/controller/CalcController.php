<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use examples\MyApp\service\CalculatorService;

#[RestController]
#[RequestMapping(path: "/calc")]
class CalcController {

    #[Autowired]
    private CalculatorService $service;

    #[RequestMapping(path: "/add", method: [RequestMethod::POST])]
    public function sum(
        #[RequestParam] int $a,
        #[RequestParam] int $b,
    ): int {
        return $this->service->add($a, $b);
    }


    #[RequestMapping(path: "/subtract", method: [RequestMethod::POST])]
    public function subtract(
        #[RequestParam] int $a,
        #[RequestParam] int $b,
    ): int {
        return $this->service->subtract($a, $b);
    }

    #[RequestMapping(path: "/multiply", method: [RequestMethod::POST])]
    public function multiply(
        #[RequestParam] int $a,
        #[RequestParam] int $b,
    ): int {
        return $this->service->multiply($a, $b);
    }

    #[RequestMapping(path: "/divide", method: [RequestMethod::POST])]
    public function divide(
        #[RequestParam] int $a,
        #[RequestParam] int $b,
    ): float {
        return $this->service->divide($a, $b);
    }


    #[RequestMapping(path: "/all", method: [RequestMethod::POST])]
    public function all(
        #[RequestParam] int $a,
        #[RequestParam] int $b,
    ): array {
        return [
            'add' => $this->service->add($a, $b),
            'subtract' => $this->service->subtract($a, $b),
            'multiply' => $this->service->multiply($a, $b),
            'divide' => $this->service->divide($a, $b),
        ];
    }

}