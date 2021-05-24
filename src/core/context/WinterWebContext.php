<?php
declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\actuator\ActuatorController;
use dev\winterframework\actuator\ActuatorEndPoints;
use dev\winterframework\actuator\DefaultActuatorController;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\exception\DuplicatePathException;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\web\HttpRequestDispatcher;

class WinterWebContext implements WebContext {
    protected RequestMappingRegistry $requestMapping;
    protected DispatcherServlet $dispatcherServlet;

    public function __construct(
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
        $this->requestMapping = new WinterRequestMappingRegistry(
            $this->ctxData,
            $this->appCtx
        );

        $this->initDispatcherServlet();

        $this->buildActuator();
    }

    protected function initDispatcherServlet() {
        $this->dispatcherServlet = new DispatcherServlet(
            $this->requestMapping,
            $this->ctxData,
            $this->appCtx
        );
    }

    public function getDispatcher(): HttpRequestDispatcher {
        return $this->dispatcherServlet;
    }

    protected function buildActuator(): void {
        $propCtx = $this->ctxData->getPropertyContext();
        if (!$propCtx->getBool('management.endpoints.enabled', false)) {
            return;
        }

        $controller = new DefaultActuatorController(
            $this->ctxData,
            $this->appCtx,
            $this->requestMapping,
            $this->dispatcherServlet
        );

        $this->ctxData->getBeanProvider()->registerInternalBean(
            $controller,
            ActuatorController::class,
            false
        );
        $refClass = RefKlass::getInstance(DefaultActuatorController::class);
        $endPoints = ActuatorEndPoints::getEndPoints();

        foreach ($endPoints as $name => $def) {
            $enabledFlag = $name . '.enabled';
            if (!$propCtx->getBool($enabledFlag, false)) {
                continue;
            }

            $path = $propCtx->get($name . '.path', $def['path']);

            $mapping = $this->requestMapping->find($path, RequestMethod::GET);
            if ($mapping != null) {
                throw new DuplicatePathException("Actuator Duplicate Path '$path' "
                    . 'detected at '
                    . ReflectionUtil::getFqName($mapping->getMapping()->getRefOwner())
                );
            }

            $mapping = new RequestMapping(
                path: $path,
                method: [RequestMethod::GET]
            );
            $mapping->setBeanClass(ActuatorController::class);
            $mapping->init(RefMethod::getInstance($refClass->getMethod($def['handler'])));

            $this->requestMapping->put($mapping);
        }
    }

}