<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\System;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\exception\NullPointerException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\util\JsonUtil;
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

    private ErrorController $errorController;

    public function __construct(
        private RequestMappingRegistry $mappingRegistry,
        private ApplicationContextData $ctxData,
        private ApplicationContext $appCtx
    ) {
    }

    private function initErrorController(): void {
        $errorController = null;
        try {
            $errorController = $this->appCtx->beanByName('errorController');
            if (!($errorController instanceof ErrorController)) {
                self::logDebug('Bean named "errorController" does not implement ErrorController');
                $errorController = null;
            }
        } /** @noinspection PhpUnusedLocalVariableInspection */
        catch (Throwable $e) {
            // ignore this error
        }
        try {
            $errorController = $this->appCtx->beanByClass(ErrorController::class);
            if (!($errorController instanceof ErrorController)) {
                self::logDebug('Bean object does not implement ErrorController');
                $errorController = null;
            }
        } catch (Throwable $e) {
            self::logDebug('No controller has implemented ErrorController', [$e]);
        }

        if ($errorController == null) {
            $errorController = $this->appCtx->beanByClass(DefaultErrorController::class);
        }
        /** @var ErrorController $errorController */
        $this->errorController = $errorController;
    }

    public function dispatch(): void {
        $this->initErrorController();
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
            $this->handleError(
                HttpStatus::$NOT_FOUND,
                new WinterException('Could not find Requested URI ['
                    . $request->getMethod() . ']' . $uri)
            );
        }

        try {
            $this->routeRequest($matchedRoute, $request);
        } catch (Throwable $t) {
            self::logException($t);
            $this->handleError(
                HttpStatus::$INTERNAL_SERVER_ERROR,
                $t
            );
        }
    }

    private function handleError(HttpStatus $status, Throwable $t = null): void {
        $this->errorController->handleError($status, $t);
        System::exit();
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
        /** @var ResponseRenderer $renderer */
        $renderer = $this->appCtx->beanByClass(ResponseRenderer::class);

        $mapping = $route->getMapping();
        $method = $mapping->getRefOwner();
        if ($mapping->getBeanName() != '') {
            $controller = $this->appCtx->beanByName($mapping->getBeanName());
        } else if ($mapping->getBeanClass() != '') {
            $controller = $this->appCtx->beanByClass($mapping->getBeanClass());
        } else {
            $controller = $this->appCtx->beanByClass($method->getDeclaringClass()->getName());
        }
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
                $this->handleError(
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
            $args[$var->getVariableName()] = $this->getRequestParamValue($request, $var);
        }

        /**
         * STEP - 4 : Map Request BODY to Object
         */
        if ($bodyMap) {

            try {
                $args[$bodyMap->getVariableName()] = $this->parseBody($request, $bodyMap, $contentType);
            } catch (Throwable $e) {
                self::logError('Could not understand the request - with error '
                    . $e::class . ': ' . $e->getMessage() . ', file: ' . $e->getFile()
                    . ', line: ' . $e->getLine()
                );
                $this->handleError(HttpStatus::$BAD_REQUEST);
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
         * STEP - 6.1 : pre-intercept Controller
         */
        if ($controller instanceof ControllerInterceptor) {
            $controller->preHandle($request, $method->getDelegate());
        }

        /**
         * STEP - 6.2 : Execute Method
         */
        $out = $method->invokeArgs($controller, $args);

        if ($out instanceof ResponseEntity) {
            $entity = $out;
        } else {
            $entity = ResponseEntity::ok()->setBody($out);
        }

        /**
         * STEP - 6.3 : post-intercept Controller
         */
        if ($controller instanceof ControllerInterceptor) {
            $controller->postHandle($request, $entity, $method->getDelegate());
        }

        $renderer->renderAndExit($entity);
    }

    /**
     * ----
     * Parse Body and Map to object
     *
     * @param HttpRequest $request
     * @param RequestBody $body
     * @param string $contentType
     * @return object|string|null
     * @throws
     */
    protected function parseBody(
        HttpRequest $request,
        RequestBody $body,
        string $contentType
    ): object|string|null {


        $rawBody = $request->getRawBody();
        $varType = $body->getVariableType();
        if ($varType === 'string') {
            return $rawBody;
        }

        if (str_contains($contentType, MediaType::APPLICATION_FORM_URLENCODED)
            || str_contains($contentType, MediaType::MULTIPART_FORM_DATA)) {

            try {
                return ObjectCreator::createObject($varType, $_POST);
            } catch (Throwable $e) {
                self::logException($e);

                $this->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Unexpected data passed')
                );
            }

        } else if (!$body->disableParsing
            && (empty($contentType) || str_contains($contentType, MediaType::APPLICATION_JSON))) {

            self::logInfo('JSON Body: ' . $rawBody);

            $row = [];
            try {
                $row = JsonUtil::decodeArray($rawBody);
            } catch (Throwable $e) {
                self::logException($e);

                $this->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Invalid JSON, ' . $e->getMessage())
                );
            }

            try {
                return ObjectCreator::createObject($varType, $row);
            } catch (Throwable $e) {
                self::logException($e);

                $this->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Wrong JSON data passed')
                );
            }

        } else if (!$body->disableParsing && (str_contains($contentType, MediaType::APPLICATION_XML)
                || str_contains($contentType, MediaType::TEXT_XML))) {

            self::logInfo('XML Body: ' . $rawBody);

            try {
                return ObjectCreator::createObjectXml($varType, $rawBody);
            } catch (Throwable $e) {
                self::logException($e);

                $this->handleError(
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Wrong XML data passed')
                );
            }
        }

        try {
            return ObjectCreator::createObject($varType, $rawBody);
        } catch (Throwable $e) {
            self::logException($e);

            $this->handleError(
                HttpStatus::$BAD_REQUEST,
                new WinterException('Bad Request: Unexpected data passed')
            );
        }
        return null;
    }

    /**
     * ---------
     * Find and Map the requested parameter to controller argument
     *
     * @param HttpRequest $request
     * @param RequestParam $var
     * @return mixed
     */
    private function getRequestParamValue(HttpRequest $request, RequestParam $var): mixed {
        $type = $var->getVariableType();

        $value = match ($var->getSource()) {
            'get' => $request->getQueryParam($var->name),
            'post' => $request->getPostParam($var->name),
            'cookie' => $request->getCookie($var->name),
            'header' => $type->hasType('array') ?
                $request->getHeader($var->name) : $request->getFirstHeader($var->name),
            default => $request->hasQueryParam($var->name) ?
                $request->getQueryParam($var->name) : $request->getPostParam($var->name),
        };

        if ($var->required && is_null($value)) {
            $this->handleError(
                HttpStatus::$BAD_REQUEST,
                new WinterException('Bad Request: ' . $var->getRequiredText())
            );
        }

        try {
            return $type->castValue(
                $value,
                0,
                $var->defaultValue
            );
        } catch (NullPointerException $ex) {
            self::logException($ex);

            $this->handleError(
                HttpStatus::$BAD_REQUEST,
                new WinterException('Bad Request: ' . $var->getRequiredText())
            );
        } catch (Throwable $e) {
            self::logException($e);

            $this->handleError(
                HttpStatus::$BAD_REQUEST,
                new WinterException('Bad Request: ' . $var->getInvalidText())
            );
        }
        return null;
    }

}