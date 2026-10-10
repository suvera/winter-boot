<?php

declare(strict_types=1);

namespace dev\winterframework\core\web;

use dev\winterframework\core\aop\AopExecutionContext;
use dev\winterframework\core\aop\NativeAopDriver;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\System;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\error\ErrorController;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\exception\NullPointerException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\util\BeanFinderTrait;
use dev\winterframework\util\JsonUtil;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
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
 * renders the outcome. Controller beans carry no interception, so AOP
 * attributes on endpoints are executed here rather than through advice.
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

        // Restore, don't clear: an in-process dispatch (e.g. an MCP tool
        // call) runs inside another request, which must stay bound after.
        $outerRequest = $this->appCtx->getCurrentHttpRequest();
        $outerResponse = $this->appCtx->getCurrentHttpResponse();
        $this->appCtx->setCurrentHttpRequest($request);
        $this->appCtx->setCurrentHttpResponse($response);
        try {
            $this->doDispatch($request, $response, $serverPath, microtime(true));
        } finally {
            $this->appCtx->setCurrentHttpRequest($outerRequest);
            $this->appCtx->setCurrentHttpResponse($outerResponse);
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
        mixed $serverPath,
        ?float $startTime = null
    ): void {
        // Arrival trace: logged instantly, before routing or rendering.
        $this->logRequestReceived($request);

        $uri = self::stripContextPath(trim($request->getUri(), '/'), $serverPath);

        $matchedRoute = $this->mappingRegistry->find($uri, $request->getMethod());

        if ($matchedRoute === null) {
            // WB-2.1-04: $uri is attacker-controlled; never log/echo it raw.
            $safeUri = self::sanitizeUriForError($uri);
            // A client error, not a server failure: INFO keeps scanners' noise out of ERROR.
            self::logInfo('Could not find Requested URI [' . $request->getMethod() . '] ' . $safeUri);
            $this->handleError(
                $request,
                $response,
                HttpStatus::$NOT_FOUND,
                new WinterException('Could not find Requested URI ['
                    . $request->getMethod() . ']' . $safeUri),
                $startTime
            );
            return;
        }

        try {
            $this->routeRequest($matchedRoute, $request, $response, $startTime);
        } catch (Throwable $t) {
            if (self::isClientError($t)) {
                // Expected outcome the error controller renders; no stack trace.
                // The message is app text and may carry request data, so it is not logged.
                self::logInfo('Request [' . $request->getMethod() . '] ' . self::sanitizeUriForError($uri)
                    . ' answered ' . $t->getStatus()->getValue() . ' (' . $t::class . ')');
            } else {
                self::logException($t);
            }
            $this->handleError(
                $request,
                $response,
                HttpStatus::$INTERNAL_SERVER_ERROR,
                $t,
                $startTime
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
        ?Throwable $t = null,
        ?float $startTime = null
    ): void {
        // Trace first: on classic SAPIs the error render below exits the process.
        $this->logRequestCompleted($request, $response, $startTime, $status->getValue());

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

        if ($request->exitsAfterResponse()) {
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
        ResponseEntity $response,
        ?float $startTime = null
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
                    if (self::isContentTypeSupported($contentType, $mediaType)) {
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
                        ),
                        $startTime
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
                    $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, $e, $startTime);
                    return;
                } catch (Throwable $e) {
                    self::logError(
                        'Invalid parameter in the request - with error '
                            . $e::class . ': ' . $e->getMessage() . ', file: ' . $e->getFile()
                            . ', line: ' . $e->getLine()
                    );
                    $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, null, $startTime);
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
                    $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, $e, $startTime);
                    return;
                } catch (Throwable $e) {
                    self::logError(
                        'Could not understand the request - with error '
                            . $e::class . ': ' . $e->getMessage() . ', file: ' . $e->getFile()
                            . ', line: ' . $e->getLine()
                    );
                    $this->handleError($request, $response, HttpStatus::$BAD_REQUEST, null, $startTime);
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

            $missing = self::missingParameters($method, $args);
            if (!empty($missing)) {
                $this->handleError(
                    $request,
                    $response,
                    HttpStatus::$BAD_REQUEST,
                    new WinterException('Bad Request: Missing parameter ' . $missing[0]),
                    $startTime
                );
                return;
            }

            /**
             * STEP - 6.1 : pre-intercept Controller
             */
            if ($controller instanceof ControllerInterceptor) {
                if (!$controller->preHandle($request, $response, $method->getDelegate())) {
                    $this->finishVetoed($interceptor, $renderer, $request, $response);
                    $this->logRequestCompleted($request, $response, $startTime);
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
             *
             * Intentional duplicate of NativeAopDriver::begin()/finish(): the
             * same protocol, kept inline on purpose. Controllers stay out of
             * native advice (ClassResourceScanner skips them) because the
             * extension verifies a stopExecution() value against the declared
             * return type, and existing endpoints rely on an aspect supplying
             * e.g. a ResponseEntity regardless of that type. Deliberate
             * differences: no return-type verification, no #[Async] enqueue
             * (rejected on controllers), and no "AOP invocation failed" log
             * (request errors are already logged by the dispatcher).
             * Any other protocol change must be made in both places.
             */
            $aopRegistry = $this->ctxData->getAopRegistry();
            $aopOwner = $method->getDeclaringClass()->getName();
            $aopName = $method->getShortName();
            $aopInterceptor = $aopRegistry->has($aopOwner, $aopName)
                ? $aopRegistry->get($aopOwner, $aopName)
                : null;
            $aopExCtx = $aopInterceptor !== null
                ? new AopExecutionContext($controller, self::positionalArguments($method, $args))
                : null;

            // Native path: winter_boot_advise() already intercepts this
            // method in the VM with the same protocol, so driving it here
            // as well would run every aspect twice. Controller methods are
            // never advised (they carry no proxy), hence the is_advised()
            // check instead of a blanket flag.
            $nativeDriven = $aopInterceptor !== null
                && NativeAopDriver::isNativeActive()
                && winter_boot_is_advised($aopOwner, $aopName);

            if ($aopInterceptor !== null && !$nativeDriven) {
                $aopInterceptor->aspectBegin($aopExCtx);
                $aopExCtx->setBeginDone();
            }

            if ($aopExCtx !== null && !$nativeDriven && $aopExCtx->isStopExecution()) {
                $out = $aopExCtx->getResult();
            } else {
                try {
                    $out = $method->invokeArgs($controller, $args);
                } catch (Throwable $e) {
                    if ($aopInterceptor !== null && !$nativeDriven) {
                        $aopExCtx->setException($e);
                        $aopExCtx->setFailed();
                        $aopInterceptor->aspectFailed($aopExCtx, $e);
                    }
                    throw $e;
                }
                if ($aopInterceptor !== null && !$nativeDriven) {
                    $aopExCtx->setSuccess();
                    $aopExCtx->setResult($out);
                    try {
                        $aopInterceptor->aspectCommit($aopExCtx, $out);
                        $aopExCtx->setSuccess();
                    } catch (Throwable $e) {
                        $aopExCtx->setException($e);
                        $aopExCtx->setCommitFailed();
                        if ($aopExCtx->getPropagatedCommitFailure() === $e) {
                            throw $e;
                        }
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

            $this->logRequestCompleted($request, $response, $startTime);
        } finally {
            // Single observation point: stop() records on every call, so
            // the timer must stop exactly once, on all paths alike.
            // Route template, not the raw URI: path values would create an
            // unbounded number of metric series.
            $timer->stop(['path' => self::routeLabel($route), 'method' => $request->getMethod()]);
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
                // WB-003: bind from the request, never process globals.
                return ObjectCreator::createObject($varType, $request->getPostParams());
            } catch (Throwable $e) {
                self::logBadInput($e);
                throw new WinterException('Bad Request: Unexpected data passed');
            }
        } else if (str_contains($contentType, MediaType::MULTIPART_FORM_DATA)) {
            // WB-003: bind from the request, never process globals.
            $data = $request->getPostParams();
            $files = $request->getFiles();
            if (!empty($files)) {
                $data = array_merge($data, $files);
            }
            try {
                return ObjectCreator::createObject($varType, $data);
            } catch (Throwable $e) {
                self::logBadInput($e);
                throw new WinterException('Bad Request: Unexpected data passed');
            }
        } else if (
            !$body->disableParsing
            && (empty($contentType) || str_contains($contentType, MediaType::APPLICATION_JSON))
        ) {
            // WB-009: never log raw bodies; they may carry credentials.
            try {
                $row = JsonUtil::decodeArray($rawBody);
            } catch (Throwable $e) {
                self::logBadInput($e);
                throw new WinterException('Bad Request: Invalid JSON, ' . $e->getMessage());
            }

            try {
                return ObjectCreator::createObject($varType, $row);
            } catch (Throwable $e) {
                self::logBadInput($e);
                throw new WinterException('Bad Request: Wrong JSON data passed, ' . $e->getMessage());
            }
        } else if (!$body->disableParsing && (str_contains($contentType, MediaType::APPLICATION_XML)
            || str_contains($contentType, MediaType::TEXT_XML))) {
            // WB-009: never log raw bodies; they may carry credentials.
            try {
                return ObjectCreator::createObjectXml($varType, $rawBody);
            } catch (Throwable $e) {
                self::logBadInput($e);
                throw new WinterException('Bad Request: Wrong XML data passed');
            }
        }

        try {
            return ObjectCreator::createObject($varType, $rawBody);
        } catch (Throwable $e) {
            self::logBadInput($e);
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
            self::logBadInput($ex, 'Parameter "' . $var->name . '": ');
            throw new WinterException('Bad Request: ' . $var->getRequiredText());
        } catch (Throwable $e) {
            self::logBadInput($e, 'Parameter "' . $var->name . '": ');
            throw new WinterException('Bad Request: ' . $var->getInvalidText());
        }
    }


    /**
     * Compares media types exactly after stripping parameters (e.g.
     * "; charset=utf-8") and case. A substring match would wrongly accept
     * types like "application/json-malicious" for "application/json".
     */
    protected static function isContentTypeSupported(string $contentType, string $mediaType): bool {
        $actual = strtolower(trim((string)strtok($contentType, ';')));
        $expected = strtolower(trim((string)strtok($mediaType, ';')));
        return $actual !== '' && $actual === $expected;
    }

    /**
     * A failure the client caused and the error controller answers with
     * a 4xx: a HttpRestException carrying a status below 500.
     */
    public static function isClientError(Throwable $t): bool {
        if (!$t instanceof HttpRestException) {
            return false;
        }
        $code = $t->getStatus()->getValue();
        return $code >= 400 && $code < 500;
    }

    /**
     * Request input that could not be bound (query values, body) is the
     * client's mistake, answered with 400: DEBUG, one line, no stack trace.
     */
    protected static function logBadInput(Throwable $e, string $prefix = ''): void {
        // The parameter name comes from code; only the message describes the input.
        self::logDebug('Bad request input: ' . $prefix . $e::class . ': ' . $e->getMessage());
    }

    /**
     * Removes the configured context path from a trimmed URI, only on a
     * segment boundary ("api" strips "api/users", not "apiary/x").
     */
    public static function stripContextPath(string $uri, string $contextPath): string {
        if ($contextPath === '') {
            return $uri;
        }
        if ($uri === $contextPath) {
            return '';
        }
        if (str_starts_with($uri, $contextPath . '/')) {
            return trim(substr($uri, strlen($contextPath)), '/');
        }
        return $uri;
    }

    /**
     * Required parameters with no bound argument. A bound null counts as
     * present (array_key_exists, not isset).
     *
     * @return string[]
     */
    public static function missingParameters(\ReflectionFunctionAbstract|RefMethod $method, array $args): array {
        $missing = [];
        foreach ($method->getParameters() as $param) {
            if (array_key_exists($param->getName(), $args) || $param->isOptional()) {
                continue;
            }
            $missing[] = $param->getName();
        }
        return $missing;
    }

    /**
     * Name-keyed endpoint arguments re-ordered by parameter position, the
     * shape every other AOP entry point hands to aspects (`#{param}`
     * templates and key generators read arguments by position). An omitted
     * optional parameter takes its declared default.
     *
     * @return array<int, mixed>
     */
    public static function positionalArguments(\ReflectionFunctionAbstract|RefMethod $method, array $args): array {
        $out = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $args)) {
                $out[$param->getPosition()] = $args[$name];
            } else if ($param->isDefaultValueAvailable()) {
                $out[$param->getPosition()] = $param->getDefaultValue();
            }
        }
        return $out;
    }

    /**
     * Metric label for a matched route: its declared path template.
     */
    public static function routeLabel(MatchedRequestMapping $route): string {
        $paths = [];
        foreach ($route->getMapping()->getUriPaths() as $uriPath) {
            $paths[] = '/' . trim($uriPath->getRaw(), '/');
        }
        return implode('|', $paths);
    }

    /**
     * Whether an app-level interceptor pattern applies to a URI. Tested on
     * the raw URI and on the normalised form routing uses (repeated slashes
     * collapsed), so "//admin/x" cannot reach an "admin/x" route while
     * skipping an "^/admin" interceptor.
     */
    public static function interceptorMatches(string $regexPath, string $uri): bool {
        $pattern = '/' . $regexPath . '/';
        if (preg_match($pattern, $uri)) {
            return true;
        }
        $normalised = '/' . trim((string)preg_replace('#/+#', '/', $uri), '/');
        return $normalised !== $uri && preg_match($pattern, $normalised) === 1;
    }

    /**
     * Completes a request vetoed by a ControllerInterceptor: renders the
     * response as-is and still runs afterCompletion() hooks.
     */
    protected function finishVetoed(
        InterceptorRegistry $registry,
        ResponseRenderer $renderer,
        HttpRequest $request,
        ResponseEntity $response
    ): void {
        $renderer->render($response, $request);
        try {
            $this->afterCompletion($registry, $request, $response);
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    /**
     * Strips control characters and truncates a request URI before it is
     * echoed into 404 messages and logs, blocking log injection and
     * reflected content via the error body.
     */
    protected static function sanitizeUriForError(string $uri): string {
        $uri = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $uri);
        return strlen($uri) > 512 ? substr($uri, 0, 512) : $uri;
    }

    /**
     * Whether request tracing is enabled via
     * `winter.web.request.enableTrace: true` in application.yml.
     */
    protected function isTraceEnabled(): bool {
        return $this->ctxData->getPropertyContext()->getBool('winter.web.request.enableTrace', false);
    }

    /**
     * Logs the arrival line instantly when a request reaches the dispatcher:
     * HTTP method plus endpoint URI. No status or duration exists yet — the
     * START marker tells it apart from the completion line. Only safe
     * metadata is logged — never headers, bodies or query values.
     *
     * @param HttpRequest $request Incoming request.
     */
    protected function logRequestReceived(HttpRequest $request): void {
        if (!$this->isTraceEnabled()) {
            return;
        }

        // WB-2.1-04: the URI is attacker-controlled; never log it raw.
        self::logInfo(sprintf(
            'TRACE START %s %s',
            $request->getMethod(),
            self::sanitizeUriForError($request->getUri())
        ));
    }

    /**
     * Logs the completion line once the exchange finished (success or
     * failure): endpoint URI, HTTP method, response status and time taken.
     * The FINISH marker plus status/duration tell it apart from the arrival
     * line. Only safe metadata is logged — never headers, bodies or query
     * values.
     *
     * @param HttpRequest $request Finished request.
     * @param ResponseEntity $response Finished response.
     * @param float|null $startTime microtime(true) captured at dispatch entry.
     * @param int|null $statusCode Explicit status (error paths set it on the
     *     response only during rendering, so callers pass it directly).
     */
    protected function logRequestCompleted(
        HttpRequest $request,
        ResponseEntity $response,
        ?float $startTime,
        ?int $statusCode = null
    ): void {
        if (!$this->isTraceEnabled()) {
            return;
        }

        $status = $statusCode ?? $response->getStatus()->getValue();
        $durationMs = $startTime !== null ? (microtime(true) - $startTime) * 1000 : 0.0;
        // WB-2.1-04: the URI is attacker-controlled; never log it raw.
        $uri = self::sanitizeUriForError($request->getUri());
        self::logInfo(sprintf(
            'TRACE FINISH %s %s -> %d (%.1f ms)',
            $request->getMethod(),
            $uri,
            $status,
            $durationMs
        ));
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
            if (!self::interceptorMatches($regexPath, $uri)) {
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

            if (!self::interceptorMatches($regexPath, $uri)) {
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

            if (!self::interceptorMatches($regexPath, $uri)) {
                continue;
            }
            foreach ($interceptors as $interceptor) {
                $interceptor->afterCompletion($request, $entity, $ex);
            }
        }
    }
}
