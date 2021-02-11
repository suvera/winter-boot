<?php
declare(strict_types=1);

namespace examples\MyApp\service;

use dev\winterframework\stereotype\Component;
use examples\MyApp\stereotype\MyAspect;

#[Component]
class MyComponent {

    #[MyAspect]
    public function aspectOrientedDemo() {
        echo "inside Aspect\n";
    }
}