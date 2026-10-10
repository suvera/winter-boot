<?php

declare(strict_types=1);

namespace dev\winterframework\core\context;

use dev\winterframework\actuator\ActuatorController;
use dev\winterframework\actuator\ActuatorEndPoints;
use dev\winterframework\actuator\DefaultActuatorController;
use dev\winterframework\core\web\config\DefaultWebMvcConfigurer;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\core\web\route\WinterRequestMappingRegistry;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\exception\DuplicatePathException;
use dev\winterframework\mcp\exception\McpDefinitionException;
use dev\winterframework\mcp\invoke\DefaultMcpToolInvoker;
use dev\winterframework\mcp\McpController;
use dev\winterframework\mcp\McpServer;
use dev\winterframework\mcp\McpToolRegistry;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\util\BeanFinderTrait;
use dev\winterframework\web\HttpRequestDispatcher;
use dev\winterframework\web\session\SessionManager;
use SessionHandlerInterface;

class WinterWebContext implements WebContext {
    use BeanFinderTrait;

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

        $this->buildMcp();

        $this->buildSessionDefaults();

        $this->configureWebMvc();
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
                throw new DuplicatePathException(
                    "Actuator Duplicate Path '$path' "
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

    /**
     * Serves the application's #[McpTool] methods on POST
     * <context-path><winter.mcp.path> (default /mcp). Nothing is
     * registered, and nothing runs, when the application has no tools.
     * Tool definitions are derived here, so a broken tool fails at boot.
     */
    protected function buildMcp(): void {
        $resources = $this->ctxData->getResources();
        if (!$resources->hasAttribute(McpTool::class)) {
            return;
        }
        $registry = McpToolRegistry::fromResources($resources);
        if ($registry->isEmpty()) {
            return;
        }

        $propCtx = $this->ctxData->getPropertyContext();
        $path = '/' . trim((string)$propCtx->get('winter.mcp.path', McpController::DEFAULT_PATH), '/');
        if ($path === '/') {
            throw new McpDefinitionException('winter.mcp.path must not be empty');
        }
        $contextPath = (string)($propCtx->get('server.context-path', '/') ?? '/');
        $allowedOrigins = $propCtx->get('winter.mcp.allowedOrigins', []);
        if (!is_array($allowedOrigins)) {
            $allowedOrigins = [(string)$allowedOrigins];
        }

        $serverInfo = [
            'name' => (string)$propCtx->get('winter.mcp.serverName', $this->appCtx->getApplicationName()),
            'version' => $this->appCtx->getApplicationVersion(),
        ];

        $server = new McpServer(
            $registry,
            new DefaultMcpToolInvoker($this->appCtx, $this->dispatcherServlet, $contextPath),
            $serverInfo,
            (string)$propCtx->get('winter.mcp.instructions', ''),
            $this->appCtx,
        );
        $controller = new McpController(
            $server,
            array_values(array_filter($allowedOrigins, 'is_string')),
            (int)$propCtx->get('winter.mcp.maxBodyBytes', McpController::DEFAULT_MAX_BODY_BYTES),
        );

        $this->ctxData->getBeanProvider()->registerInternalBean(
            $controller,
            McpController::class,
            false
        );
        $this->ctxData->getBeanProvider()->registerInternalBean(
            $registry,
            McpToolRegistry::class,
            false
        );

        $refClass = RefKlass::getInstance(McpController::class);
        foreach (['post' => [RequestMethod::POST], 'notAllowed' => [RequestMethod::GET, RequestMethod::DELETE]]
                 as $handler => $methods) {
            $mapping = new RequestMapping(path: $path, method: $methods);
            $mapping->setBeanClass(McpController::class);
            $mapping->init(RefMethod::getInstance($refClass->getMethod($handler)));
            // put() throws DuplicatePathException when the app already uses the path.
            $this->requestMapping->put($mapping);
        }
    }

    /**
     * Registers session defaults so no per-project SessionConfig is
     * needed for the common case. Every bean is registered with
     * overwrite disabled: an application bean of the same class (or, for
     * the store, the same SessionHandlerInterface return type) silently
     * wins, following the same pattern as the actuator above.
     *
     * Defaults: a stateless SessionManager and PHP's file-based
     * SessionHandler as the store. SessionOptions is deliberately NOT
     * a bean: each login flow constructs its own (admin and user
     * sessions need different cookies), so there is no single
     * configuration to share. Redis
     * (dev\winterframework\data\redis\session\RedisSessionStore in
     * winter-data-redis) and PDBC stores are opt-in via the
     * application's own beans.
     */
    protected function buildSessionDefaults(): void {
        $provider = $this->ctxData->getBeanProvider();

        $provider->registerInternalBean(
            new SessionManager(),
            SessionManager::class,
            false
        );

        $provider->registerInternalBean(
            new \SessionHandler(),
            SessionHandlerInterface::class,
            false
        );
    }

    protected function configureWebMvc(): void {
        /** @var WebMvcConfigurer $webConfig */
        $webConfig = $this->findBean(
            $this->appCtx,
            'webMvcConfigurer',
            WebMvcConfigurer::class,
            DefaultWebMvcConfigurer::class
        );
        $webConfig->addInterceptors($this->ctxData->getInterceptorRegistry());
    }
}
