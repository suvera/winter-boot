<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\System;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\exception\NullPointerException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\util\BeanFinderTrait;
use dev\winterframework\util\JsonUtil;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\http\SwooleRequest;
use dev\winterframework\web\HttpRequestDispatcher;
use dev\winterframework\web\MediaType;
use ReflectionNamedType;
use Throwable;

class DispatcherServlet implements HttpRequestDispatcher {
    use Wlf4p;
    use BeanFinderTrait;

    protected ErrorController $errorController;

    public function __construct(
        protected RequestMappingRegistry $mappingRegistry,
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
    }

    private function initialize(): void {
        if (!isset($this->errorController)) {
            $this->errorController = $this->findBean(
                $this->appCtx,
                'errorController',
                ErrorController::class,
                DefaultErrorController::class
            );
        }
    }

    protected function initHttpRequest(): HttpRequest {
        return new HttpRequest();
    }

    public function dispatch(HttpRequest $request = null, ResponseEntity $response = null): void {
        $this->initialize();
        $serverPath = $this->ctxData->getPropertyContext()->get('server.context-path', '/');
        $serverPath = isset($serverPath) ? trim($serverPath, '/') : '';

        if (!$request) {
            $request = $this->initHttpRequest();
        }
        if (!$response) {
            $response = new ResponseEntity();
        }

        $uri = $request->getUri();
        $uri = trim($uri, '/');

        if (strlen($serverPath) && str_starts_with($uri, $serverPath)) {
            $uri = substr($uri, strlen($serverPath));
            $uri = trim($uri, '/');
        }

        $matchedRoute = $this->mappingRegistry->find($uri, $request->getMethod());

        if ($matchedRoute === null) {
            self::logError('Could not find Requested URI [' . $request->getMethod() . '] ' . $uri);
            $this->handleError(
                $request,
                $response,
                HttpStatus::$NOT_FOUND,
                new WinterException('Could not find Requested URI ['
                    . $request->getMethod() . ']' . $uri),
            );
            return;
        }

        try {
            $this->routeRequest($matchedRoute, $request, $response);
        } catch (Throwable $t) {
            self::logException($t);
            $this->handleError(
                $request,
                $response,
                HttpStatus::$INTERNAL_SERVER_ERROR,
                $t
            );
            return;
        }
    }

    protected function handleError(
        HttpRequest $request,
        ResponseEntity $response,
        HttpStatus $status,
        Throwable $t = null
    ): void {
        $this->errorController->handleError($request, $response, $status, $t);

        try {
            $this->afterCompletion(
                $this->ctxData->getInterceptorRegistry(),
                $request,
                $response,
                $t
            );
        } catch (Throwable $e) {
            self::logException($e);
        }

        if (!($request instanceof SwooleRequest)) {
            System::exit();
        }
    }

    /**
     * @param MatchedRequestMapping $route
     * @param HttpRequest $request
     * @param ResponseEntity $response
     * @throws
     */
    protected function routeRequest(
        MatchedRequestMapping $route,
        HttpRequest $request,
        ResponseEntity $response
    ): void {
        /** @var ResponseRenderer $renderer */
        $renderer = $this->appCtx->beanByClass(ResponseRenderer::class);
        /** @var PrometheusMetricRegistry $metrics */
        $metrics = $this->appCtx->beanByClass(PrometheusMetricRegistry::class);
        $interceptor = $this->ctxData->getInterceptorRegistry();

        $timer = $metrics->startTimer('http_request_duration');
        if (!$this->preHandle($interceptor, $request, $response)) {
            $renderer->render($response, $request);
            return;
        }

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
                    $request,
                    $response,
                    HttpStatus::$BAD_REQUEST,
                    new WinterException(
                        'Bad Request: expected request types ['
                            . implode(', ', $consumes)
                            . ', but got "' . $contentType . '"'
                    )
                );
                return;
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
            try {
                $args[$var->getVariableName()] = $this->getRequestParamValue($request, $var);
            } catch (WinterException $e) {
                $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, $e);
                return;
            } catch (Throwable $e) {
                self::logError(
                    'Invalid parameter in the request - with error '
                        . $e::class . ': ' . $e->getMessage() . ', file: ' . $e->getFile()
                        . ', line: ' . $e->getLine()
                );
                $this->handleError($request, $response, HttpStatus::$BAD_REQUEST);
                return;
            }
        }

        /**
         * STEP - 4 : Map Request BODY to Object
         */
        if ($bodyMap) {

            try {
                $args[$bodyMap->getVariableName()] = $this->parseBody($request, $bodyMap, $contentType);
            } catch (WinterException $e) {
                $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, $e);
                return;
            } catch (Throwable $e) {
                self::logError(
                    'Could not understand the request - with error '
                        . $e::class . ': ' . $e->getMessage() . ', file: ' . $e->getFile()
                        . ', line: ' . $e->getLine()
                );
                $this->handleError($request, $response, HttpStatus::$BAD_REQUEST);
                return;
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
            } else if ($type->getName() === ResponseEntity::class) {
                $args[$injectableParam->getName()] = $response;
            }
        }

        foreach ($method->getParameters() as $param) {
            if (isset($args[$param->getName()])) {
                continue;
            }
            if (!$param->isOptional()) {
                $this->handleError(
                    $request,
                    $response,
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Missing parameter ' . $param->getName())
                );
                return;
            }
        }

        /**
         * STEP - 6.1 : pre-intercept Controller
         */
        if ($controller instanceof ControllerInterceptor) {
            if (!$controller->preHandle($request, $response, $method->getDelegate())) {
                $renderer->render($response, $request);
                return;
            }
        }

        /**
         * STEP - 6.2 : Execute Method
         */
        $out = $method->invokeArgs($controller, $args);

        if ($out instanceof ResponseEntity) {
            $response->merge($out);
        } else {
            $response->setBody($out);
        }


        /**
         * STEP - 6.3 : post-intercept Controller
         */
        $this->postHandle($interceptor, $request, $response);
        if ($controller instanceof ControllerInterceptor) {
            $controller->postHandle($request, $response, $method->getDelegate());
        }

        $renderer->render($response, $request);
        $timer->stop(['path' => $request->getUri(), 'method' => $request->getMethod()]);

        try {
            $this->afterCompletion($interceptor, $request, $response);
        } catch (Throwable $e) {
            self::logException($e);
        }
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

        if (
            str_contains($contentType, MediaType::APPLICATION_FORM_URLENCODED)
            || str_contains($contentType, MediaType::MULTIPART_FORM_DATA)
        ) {

            try {
                return ObjectCreator::createObject($varType, $_POST);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Bad Request: Unexpected data passed');
            }
        } else if (
            !$body->disableParsing
            && (empty($contentType) || str_contains($contentType, MediaType::APPLICATION_JSON))
        ) {

            self::logInfo('JSON Body: ' . $rawBody);

            try {
                $row = JsonUtil::decodeArray($rawBody);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Bad Request: Invalid JSON, ' . $e->getMessage());
            }

            try {
                return ObjectCreator::createObject($varType, $row);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Bad Request: Wrong JSON data passed, ' . $e->getMessage());
            }
        } else if (!$body->disableParsing && (str_contains($contentType, MediaType::APPLICATION_XML)
            || str_contains($contentType, MediaType::TEXT_XML))) {

            self::logInfo('XML Body: ' . $rawBody);

            try {
                return ObjectCreator::createObjectXml($varType, $rawBody);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Bad Request: Wrong XML data passed');
            }
        }

        try {
            return ObjectCreator::createObject($varType, $rawBody);
        } catch (Throwable $e) {
            self::logException($e);
            throw new WinterException('Bad Request: Unexpected data passed');
        }
    }

    /**
     * ---------
     * Find and Map the requested parameter to controller argument
     *
     * @param HttpRequest $request
     * @param RequestParam $var
     * @return mixed
     */
    protected function getRequestParamValue(HttpRequest $request, RequestParam $var): mixed {
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
            throw new WinterException('Bad Request: ' . $var->getRequiredText());
        }

        try {
            return $type->castValue(
                $value,
                0,
                $var->defaultValue
            );
        } catch (NullPointerException $ex) {
            self::logException($ex);
            throw new WinterException('Bad Request: ' . $var->getRequiredText());
        } catch (Throwable $e) {
            self::logException($e);
            throw new WinterException('Bad Request: ' . $var->getInvalidText());
        }
    }


    /**
     * Interceptor execution
     *
     * @param InterceptorRegistry $registry
     * @param HttpRequest $request
     * @param ResponseEntity $entity
     * @return bool
     */
    protected function preHandle(
        InterceptorRegistry $registry,
        HttpRequest $request,
        ResponseEntity $entity
    ): bool {
        $uri = $request->getUri();
        foreach ($registry->getInterceptors() as $regexPath => $interceptors) {
            if (!preg_match('/' . $regexPath . '/', $uri)) {
                continue;
            }
            foreach ($interceptors as $interceptor) {
                if (!$interceptor->preHandle($request, $entity)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function postHandle(
        InterceptorRegistry $registry,
        HttpRequest $request,
        ResponseEntity $entity
    ): void {
        $uri = $request->getUri();
        foreach ($registry->getInterceptors() as $regexPath => $interceptors) {

            if (!preg_match('/' . $regexPath . '/', $uri)) {
                continue;
            }
            foreach ($interceptors as $interceptor) {
                $interceptor->postHandle($request, $entity);
            }
        }
    }

    protected function afterCompletion(
        InterceptorRegistry $registry,
        HttpRequest $request,
        ResponseEntity $entity,
        Throwable $ex = null
    ): void {
        $uri = $request->getUri();
        foreach ($registry->getInterceptors() as $regexPath => $interceptors) {

            if (!preg_match('/' . $regexPath . '/', $uri)) {
                continue;
            }
            foreach ($interceptors as $interceptor) {
                $interceptor->afterCompletion($request, $entity, $ex);
            }
        }
    }
}
