<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\aop\AopExecutionContext;
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

/**
 * Front controller for HTTP requests.
 *
 * Resolves the incoming request to a controller endpoint (see
 * {@see RequestMappingRegistry}), binds request data to the endpoint
 * arguments, drives method-level AOP advice around the invocation, and
 * renders the outcome. Controller beans carry no proxy, so AOP attributes
 * on endpoints are executed here rather than through a proxy override.
 * Any uncaught failure is mapped to an error response via the
 * {@see ErrorController}.
 */
class DispatcherServlet implements HttpRequestDispatcher {
    use Wlf4p;
    use BeanFinderTrait;

    protected ErrorController $errorController;

    /**
     * @param RequestMappingRegistry $mappingRegistry Resolves URIs to endpoint mappings.
     * @param ApplicationContextData $ctxData Shared container data (interceptor registries, AOP registry).
     * @param ApplicationContext $appCtx Bean source for controllers and infrastructure beans.
     */
    public function __construct(
        protected RequestMappingRegistry $mappingRegistry,
        protected ApplicationContextData $ctxData,
        protected ApplicationContext $appCtx
    ) {
    }

    /**
     * Lazily resolves the error controller bean on first use.
     */
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

    /**
     * Builds a fresh request from the PHP runtime (superglobals, input
     * stream). Used for classic SAPIs and tests; Swoole callers pass a
     * SwooleRequest instead.
     *
     * @return HttpRequest
     */
    protected function initHttpRequest(): HttpRequest {
        return new HttpRequest();
    }

    /**
     * Entry point for one HTTP exchange.
     *
     * Binds the given (or newly created) request/response as the current
     * request on the application context, routes it, and always unbinds
     * afterwards — even when routing fails. Unknown URIs yield 404, any
     * failure inside routing yields 500, both rendered by the error
     * controller.
     *
     * @param HttpRequest|null $request Current request; created when omitted.
     * @param ResponseEntity|null $response Response to fill; created when omitted.
     */
    public function dispatch(?HttpRequest $request = null, ?ResponseEntity $response = null): void {
        $this->initialize();
        $serverPath = $this->ctxData->getPropertyContext()->get('server.context-path', '/');
        $serverPath = isset($serverPath) ? trim($serverPath, '/') : '';

        if (!$request) {
            $request = $this->initHttpRequest();
        }
        if (!$response) {
            $response = new ResponseEntity();
        }

        $this->appCtx->setCurrentHttpRequest($request);
        $this->appCtx->setCurrentHttpResponse($response);
        try {
            $this->doDispatch($request, $response, $serverPath);
        } finally {
            $this->appCtx->setCurrentHttpRequest(null);
            $this->appCtx->setCurrentHttpResponse(null);
        }
    }

    /**
     * Routes one bound exchange: strips the context path, matches a route
     * (404 when none matches), and delegates to the endpoint invocation.
     * Failures inside the endpoint bubble up to dispatch() as 500. Runs
     * with the request/response bound as current on the context.
     *
     * @param HttpRequest $request Current request.
     * @param ResponseEntity $response Response to fill.
     * @param mixed $serverPath Configured context path prefix to strip.
     * @throws
     */
    protected function doDispatch(
        HttpRequest $request,
        ResponseEntity $response,
        mixed $serverPath
    ): void {
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

    /**
     * Renders a failure response through the error controller and notifies
     * interceptors via afterCompletion(). On classic SAPIs the process ends
     * here; under Swoole control returns so the worker can serve the response.
     *
     * @param HttpRequest $request Failed request.
     * @param ResponseEntity $response Response to fill.
     * @param HttpStatus $status Status to render (e.g. 404, 500).
     * @param Throwable|null $t Failure cause, when known.
     */
    protected function handleError(
        HttpRequest $request,
        ResponseEntity $response,
        HttpStatus $status,
        ?Throwable $t = null
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
     * Executes one matched endpoint in stages:
     *
     * 1. content-type check, path/query/body argument binding, injectable
     *    HttpRequest/ResponseEntity arguments;
     * 2. controller-level preHandle interceptors (a veto renders as-is);
     * 3. method-level AOP advice (begin/commit/failed, stopExecution);
     * 4. endpoint invocation, response merge, postHandle, render.
     *
     * A ResponseEntity returned (or supplied via stopExecution) is merged
     * into the response; any other value becomes the body. Endpoint
     * failures propagate to dispatch() after failed-advice runs.
     *
     * @param MatchedRequestMapping $route Matched route and URI variables.
     * @param HttpRequest $request Current request.
     * @param ResponseEntity $response Response to fill.
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
        try {
            if (!$this->preHandle($interceptor, $request, $response)) {
                try {
                    $this->afterCompletion($interceptor, $request, $response);
                } catch (Throwable $e) {
                    self::logException($e);
                }
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
                    $renderer->renderAndExit($response, $request);
                    return;
                }
            }

            /**
             * STEP - 6.2 : Execute Method (with AOP advice when present)
             *
             * Controller beans carry no proxy: the dispatcher invokes the
             * original reflected method, so method-level AOP attributes are
             * driven here instead — begin() before the body, commit()/failed()
             * around its outcome. stopExecution() skips the body and supplies
             * the response value directly.
             */
            $aopRegistry = $this->ctxData->getAopRegistry();
            $aopOwner = $method->getDeclaringClass()->getName();
            $aopName = $method->getShortName();
            $aopInterceptor = $aopRegistry->has($aopOwner, $aopName)
                ? $aopRegistry->get($aopOwner, $aopName)
                : null;
            $aopExCtx = $aopInterceptor !== null
                ? new AopExecutionContext($controller, $args)
                : null;

            if ($aopInterceptor !== null) {
                $aopInterceptor->aspectBegin($aopExCtx);
                $aopExCtx->setBeginDone();
            }

            if ($aopExCtx !== null && $aopExCtx->isStopExecution()) {
                $out = $aopExCtx->getResult();
            } else {
                try {
                    $out = $method->invokeArgs($controller, $args);
                } catch (Throwable $e) {
                    if ($aopInterceptor !== null) {
                        $aopExCtx->setException($e);
                        $aopExCtx->setFailed();
                        $aopInterceptor->aspectFailed($aopExCtx, $e);
                    }
                    throw $e;
                }
                if ($aopInterceptor !== null) {
                    $aopExCtx->setSuccess();
                    $aopExCtx->setResult($out);
                    try {
                        $aopInterceptor->aspectCommit($aopExCtx, $out);
                        $aopExCtx->setSuccess();
                    } catch (Throwable $e) {
                        $aopExCtx->setException($e);
                        $aopExCtx->setCommitFailed();
                        self::logException($e);
                    }
                }
            }

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

            try {
                $this->afterCompletion($interceptor, $request, $response);
            } catch (Throwable $e) {
                self::logException($e);
            }
        } finally {
            // Single observation point: stop() records on every call, so
            // the timer must stop exactly once, on all paths alike.
            $timer->stop(['path' => $request->getUri(), 'method' => $request->getMethod()]);
        }
    }

    /**
     * ----
     * Parse Body and Map to object
     *
     * Decodes the raw body according to its content type (JSON, XML,
     * form-urlencoded, multipart, plain text) into the declared body
     * class. Undecodable or mistyped bodies fail the request as 400.
     *
     * @param HttpRequest $request Current request.
     * @param RequestBody $body Body mapping declared by the endpoint.
     * @param string $contentType Request content type.
     * @return object|string|null Mapped body.
     * @throws WinterException When the body cannot be understood.
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

        if (str_contains($contentType, MediaType::APPLICATION_FORM_URLENCODED)) {

            try {
                return ObjectCreator::createObject($varType, $_POST);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Bad Request: Unexpected data passed');
            }
        } else if (str_contains($contentType, MediaType::MULTIPART_FORM_DATA)) {
            $data = $_POST;
            if (isset($_FILES)) {
                $data = array_merge($data, $_FILES);
            }
            try {
                return ObjectCreator::createObject($varType, $data);
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
     * Reads one declared parameter from the request source it names
     * (query, post, cookie, header, or query-then-post by default),
     * enforces required/default rules, and casts it to the declared
     * variable type. Missing required or mistyped values fail as 400.
     *
     * @param HttpRequest $request Current request.
     * @param RequestParam $var Parameter declaration of the endpoint.
     * @return mixed Bound and cast value.
     * @throws WinterException On missing required or invalid values.
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
     * Runs preHandle() of every app-level interceptor whose path pattern
     * matches the request URI. The first veto (false) stops the chain and
     * the endpoint never runs.
     *
     * @param InterceptorRegistry $registry Matching interceptors by URI pattern.
     * @param HttpRequest $request Current request.
     * @param ResponseEntity $entity Response under construction.
     * @return bool False when an interceptor vetoed the request.
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

    /**
     * Runs postHandle() of every URI-matching app-level interceptor after
     * the endpoint produced its response but before rendering.
     *
     * @param InterceptorRegistry $registry Matching interceptors by URI pattern.
     * @param HttpRequest $request Current request.
     * @param ResponseEntity $entity Response under construction.
     */
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

    /**
     * Runs afterCompletion() of every URI-matching app-level interceptor
     * once the exchange is done — after render on success, or as part of
     * error handling on failure. Individual interceptor failures are
     * contained by the caller.
     *
     * @param InterceptorRegistry $registry Matching interceptors by URI pattern.
     * @param HttpRequest $request Finished request.
     * @param ResponseEntity $entity Finished response.
     * @param Throwable|null $ex Failure cause, when the exchange failed.
     */
    protected function afterCompletion(
        InterceptorRegistry $registry,
        HttpRequest $request,
        ResponseEntity $entity,
        ?Throwable $ex = null
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
