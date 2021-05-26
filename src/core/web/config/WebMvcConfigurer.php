<?php
declare(strict_types=1);

namespace dev\winterframework\core\web\config;

interface WebMvcConfigurer {
    
    public function addInterceptors(InterceptorRegistry $registry): void;
}