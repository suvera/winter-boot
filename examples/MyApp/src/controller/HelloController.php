<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\stereotype\web\RequestParam;
use examples\MyApp\service\MyComponent;
use examples\MyApp\service\MyConfig;

#[RestController]
class HelloController {

    #[Autowired]
    private MyComponent $myComponent;

    #[Autowired]
    private MyConfig $myConfig;

    #[RequestMapping(path: "/hello", method: [RequestMethod::GET])]
    public function sayHello(
        #[RequestParam] string $name
    ): string {
        return 'Hello, ' . $name;
    }

    #[RequestMapping(path: "/hello/check", method: [RequestMethod::POST])]
    public function sayHello3(): string {
        return 'Hello, Checking ... Value1:' . $this->myConfig->getTextValue();
    }

    #[RequestMapping(path: "/hello/{name}", method: [RequestMethod::GET])]
    public function sayHello2(
        #[PathVariable] string $name
    ): string {
        return 'Hello, ' . $name . '. Value1:' . $this->myConfig->getIntValue();
    }

    #[RequestMapping(path: "/aspect/demo", method: [RequestMethod::GET])]
    public function aspectOrientedDemo(): string {
        ob_start();

        $this->myComponent->aspectOrientedDemo();

        $c = ob_get_contents();
        ob_end_clean();

        return $c;
    }


}