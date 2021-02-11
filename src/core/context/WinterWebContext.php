<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\web\HttpRequestDispatcher;

class WinterWebContext implements WebContext {
    private RequestMappingRegistry $requestMapping;
    private DispatcherServlet $dispatcherServlet;

    public function __construct(
        private ApplicationContextData $contextData,
        private ApplicationContext $applicationContext
    ) {
        $this->requestMapping = new WinterRequestMappingRegistry(
            $this->contextData,
            $this->applicationContext
        );

        $this->dispatcherServlet = new DispatcherServlet(
            $this->requestMapping,
            $this->contextData,
            $this->applicationContext
        );
    }

    public function getDispatcher(): HttpRequestDispatcher {
        return $this->dispatcherServlet;
    }

}