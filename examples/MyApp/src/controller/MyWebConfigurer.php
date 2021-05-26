<?php
declare(strict_types=1);

namespace examples\MyApp\controller;

use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\stereotype\Configuration;

#[Configuration(name: "webMvcConfigurer")]
class MyWebConfigurer implements WebMvcConfigurer {

    public function addInterceptors(InterceptorRegistry $registry): void {
        $registry->addInterceptor(new MyInterceptor(), '^\/admin\/');
    }

}