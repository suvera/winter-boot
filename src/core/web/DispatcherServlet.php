<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\System;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\HttpRequestDispatcher;
use dev\winterframework\web\MediaType;
use ReflectionNamedType;
use Throwable;

class DispatcherServlet implements HttpRequestDispatcher {
    use Wlf4p;

    public function __construct(
        private RequestMappingRegistry $mappingRegistry,
        private ApplicationContextData $ctxData,
        private ApplicationContext $appCtx
    ) {
    }

    public function dispatch(): void {
        $serverPath = $this->ctxData->getPropertyContext()->get('server.context-path', '/');
        $serverPath = isset($serverPath) ? trim($serverPath, '/') : '';

        $request = new HttpRequest();

        $uri = $request->getUri();
        $uri = trim($uri, '/');

        if (strlen($serverPath) && str_starts_with($uri, $serverPath)) {
            $uri = substr($uri, strlen($serverPath));
            $uri = trim($uri, '/');
        }

        $matchedRoute = $this->mappingRegistry->find($uri, $request->getMethod());

        if ($matchedRoute === null) {
            self::logError('Could not find Requested URI [' . $request->getMethod() . ']' . $uri);
            /** @var ErrorController $errorController */
            $errorController = $this->appCtx->beanByClass(ErrorController::class);
            $errorController->handleError(
                HttpStatus::$NOT_FOUND,
                new WinterException('Could not find Requested URI ['
                    . $request->getMethod() . ']' . $uri)
            );
            System::exit();
        }

        try {
            $this->routeRequest($matchedRoute, $request);
        } catch (Throwable $t) {
            self::logException($t);
            /** @var ErrorController $errorController */
            $errorController = $this->appCtx->beanByClass(ErrorController::class);
            $errorController->handleError(
                HttpStatus::$INTERNAL_SERVER_ERROR,
                $t
            );
            System::exit();
        }
    }

    /**
     * @param MatchedRequestMapping $route
     * @param HttpRequest $request
     * @throws
     */
    private function routeRequest(
        MatchedRequestMapping $route,
        HttpRequest $request
    ): void {

        /** @var ErrorController $errorController */
        $errorController = $this->appCtx->beanByClass(ErrorController::class);
        /** @var ResponseRenderer $renderer */
        $renderer = $this->appCtx->beanByClass(ResponseRenderer::class);

        $mapping = $route->getMapping();
        $method = $mapping->getRefOwner();
        $controller = $this->appCtx->beanByClass($method->getDeclaringClass()->getName());
        $vars = $mapping->getRequestParams();
        $pathVars = $mapping->getAllowedPathVariables();
        $bodyMap = $mapping->getRequestBody();
        $injectableParams = $mapping->getInjectableParams();
        $consumes = $mapping->consumes;

        /**
         * STEP - 1 : Check Consuming Content Types
         */
        $contentType = $request->getContentType();
        if (!empty($consumes)) {
            $success = false;
            foreach ($consumes as $mediaType) {
                if (str_contains($contentType, $mediaType)) {
                    $success = true;
                    break;
                }
            }

            if (!$success) {
                $errorController->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: expected request types ['
                        . implode(', ', $consumes)
                        . ', but got "' . $contentType . '"'
                    )
                );
            }
        }

        /**
         * STEP - 2 : Check Requested Parameters
         */
        $args = [];
        $matches = $route->getMatching();
        foreach ($matches as $key => $value) {
            if (is_string($key) && isset($pathVars[$key])) {
                $args[$pathVars[$key]->getVariableName()] = $value;
            }
        }

        /**
         * STEP - 3 : Validate Requested Parameters
         */
        foreach ($vars as $var) {
            $args[$var->getVariableName()] =
                $request->hasQueryParam($var->name) ? $request->getQueryParam($var->name)
                    : $request->getPostParam($var->name);

            if ($var->required && !isset($args[$var->getVariableName()])) {
                $errorController->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: parameter "'
                        . $var->name
                        . '" is required.'
                    )
                );
            }
        }

        /**
         * STEP - 4 : Map Request BODY to Object
         */
        if ($bodyMap) {
            if (str_contains($contentType, MediaType::APPLICATION_FORM_URLENCODED)
                || str_contains($contentType, MediaType::MULTIPART_FORM_DATA)) {

                $args[$bodyMap->getVariableName()] = ObjectCreator::createObject(
                    $bodyMap->getVariableType(), $_POST
                );
            } else if (empty($contentType) || str_contains($contentType, MediaType::APPLICATION_JSON)) {
                $args[$bodyMap->getVariableName()] = ObjectCreator::createObject(
                    $bodyMap->getVariableType(), json_decode($request->getRawBody(), true)
                );
            } else {
                $args[$bodyMap->getVariableName()] = ObjectCreator::createObject(
                    $bodyMap->getVariableType(), $request->getRawBody()
                );
            }
        }

        /**
         * STEP - 5 : Prepare Injectable Method Arguments
         */
        foreach ($injectableParams as $injectableParam) {
            if (!$injectableParam->hasType()) {
                continue;
            }
            /** @var ReflectionNamedType $type */
            $type = $injectableParam->getType();
            if ($type->isBuiltin()) {
                continue;
            }

            if ($type->getName() === HttpRequest::class) {
                $args[$injectableParam->getName()] = $request;
            }
        }

        /**
         * STEP - 6 : Execute Method
         */
        $out = $method->invokeArgs($controller, $args);

        if ($out instanceof ResponseEntity) {
            $entity = $out;
        } else {
            $entity = ResponseEntity::ok()->setBody($out);
        }

        $renderer->renderAndExit($entity);
    }

}