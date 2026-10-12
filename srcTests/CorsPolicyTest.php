<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\core\web\cors\CorsPolicy;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\exception\WinterException;
use dev\winterframework\mcp\McpController;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\web\format\DefaultResponseRenderer;
use dev\winterframework\core\web\MatchedRequestMapping;
use dev\winterframework\io\stream\BufferedHttpOutputStream;
use dev\winterframework\core\web\route\RequestMappingRegistry;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\CrossOrigin;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use winterBootTests\Support\TestCase;

final class CorsStubRequest extends HttpRequest {
    /** @param array<string, string> $stubHeaders */
    public function __construct(private string $stubMethod, private array $stubHeaders = []) {
    }

    public function getMethod(): string {
        return $this->stubMethod;
    }

    public function getUri(): string {
        return '/api/users';
    }

    public function getFirstHeader(string $headerName): ?string {
        foreach ($this->stubHeaders as $name => $value) {
            if (strcasecmp($name, $headerName) === 0) {
                return $value;
            }
        }
        return null;
    }
}

final class CorsStubRegistry implements RequestMappingRegistry {
    public function __construct(private ?MatchedRequestMapping $found) {
    }

    public function put(RequestMapping $mapping) {
    }

    public function find(string $path, string $method): ?MatchedRequestMapping {
        return $this->found;
    }

    public function delete(string $path): void {
    }

    public function getAll(): array {
        return [];
    }
}

#[RestController]
class CorsPlainController {
    #[GetMapping(path: '/cors-plain')]
    public function plain(): void {
    }
}

#[RestController]
#[CrossOrigin(origins: ['https://partner.io'])]
class CorsClassController {
    #[GetMapping(path: '/cors-inherits')]
    public function inherits(): void {
    }

    #[GetMapping(path: '/cors-overrides')]
    #[CrossOrigin(origins: ['https://other.io'], allowCredentials: true, maxAge: 60)]
    public function overrides(): void {
    }
}

#[RestController]
#[CrossOrigin(maxAge: 5)]
class CorsNoOriginController {
    #[GetMapping(path: '/cors-bare')]
    public function bare(): void {
    }
}

#[RestController]
class CorsBadController {
    #[GetMapping(path: '/cors-bad')]
    #[CrossOrigin(origins: ['*'], allowCredentials: true)]
    public function bad(): void {
    }
}

/**
 * CORS (`winter.web.cors.*`): deny by default, exact-match origin allowlist,
 * preflight answered before routing, Vary: Origin, no `*` with credentials.
 */
final class CorsPolicyTest extends TestCase {

    /** @var string[] */
    private array $tmpDirs = [];

    public function __destruct() {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                array_map(unlink(...), glob($dir . '/*'));
                rmdir($dir);
            }
        }
    }

    private function props(string $yml): WinterPropertyContext {
        $dir = sys_get_temp_dir() . '/wbcors-' . uniqid();
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        file_put_contents($dir . '/application.yml', $yml);
        return new WinterPropertyContext([$dir]);
    }

    private function policy(bool $credentials = false): CorsPolicy {
        return new CorsPolicy(
            ['https://app.example.com', 'http://localhost:5173'],
            ['GET', 'POST', 'DELETE'],
            ['Content-Type', 'Authorization'],
            ['X-Total-Count'],
            $credentials,
            600
        );
    }

    private function preflight(string $origin, string $method = 'POST', ?string $headers = 'content-type'): CorsStubRequest {
        $h = ['Origin' => $origin, 'Access-Control-Request-Method' => $method];
        if ($headers !== null) {
            $h['Access-Control-Request-Headers'] = $headers;
        }
        return new CorsStubRequest('OPTIONS', $h);
    }

    private function run(CorsPolicy $p, CorsStubRequest $req): ResponseEntity {
        HttpStatus::init();
        $res = new ResponseEntity();
        $p->handlePreflight($req, $res);
        return $res;
    }

    // Off unless allowedOrigins is configured.
    public function testDisabledByDefault(): void {
        $this->assertNull(CorsPolicy::fromProperties($this->props("server:\n  port: 8080\n")));
    }

    public function testConfigLoadsFromYaml(): void {
        $p = CorsPolicy::fromProperties($this->props(
            "winter:\n  web:\n    cors:\n      allowedOrigins:\n        - https://app.example.com\n      maxAge: 99999\n"
        ));
        $this->assertTrue($p !== null);
        $this->assertSame('https://app.example.com', $p->resolveOrigin('https://APP.example.com/'));
    }

    // "*" with credentials is refused: CORS stays off (fail closed).
    public function testWildcardWithCredentialsDisablesCors(): void {
        $p = CorsPolicy::fromProperties($this->props(
            "winter:\n  web:\n    cors:\n      allowedOrigins: ['*']\n      allowCredentials: true\n"
        ));
        $this->assertNull($p);
    }

    public function testOriginMatchingIsExact(): void {
        $p = $this->policy();
        $this->assertSame('https://app.example.com', $p->resolveOrigin('https://app.example.com'));
        $this->assertSame('https://app.example.com', $p->resolveOrigin('HTTPS://App.Example.com'));
        $this->assertNull($p->resolveOrigin('https://evil-app.example.com'));
        $this->assertNull($p->resolveOrigin('https://app.example.com.evil.io'));
        $this->assertNull($p->resolveOrigin('http://app.example.com'));
        $this->assertNull($p->resolveOrigin('https://app.example.com:8443'));
        $this->assertNull($p->resolveOrigin('null'));
        $this->assertNull($p->resolveOrigin(''));
        $this->assertNull($p->resolveOrigin(null));
    }

    public function testNullOriginNeverMatchesEvenWithWildcard(): void {
        $p = new CorsPolicy(['*']);
        $this->assertNull($p->resolveOrigin('null'));
        $this->assertSame('*', $p->resolveOrigin('https://anything.io'));
    }

    public function testIsPreflightNeedsOriginAndRequestMethod(): void {
        $this->assertTrue(CorsPolicy::isPreflight($this->preflight('https://a.io')));
        $this->assertFalse(CorsPolicy::isPreflight(new CorsStubRequest('OPTIONS', ['Origin' => 'https://a.io'])));
        $this->assertFalse(CorsPolicy::isPreflight(new CorsStubRequest('OPTIONS')));
        $this->assertFalse(CorsPolicy::isPreflight(new CorsStubRequest('POST', [
            'Origin' => 'https://a.io', 'Access-Control-Request-Method' => 'POST',
        ])));
    }

    public function testAllowedPreflight(): void {
        $res = $this->run($this->policy(), $this->preflight('https://app.example.com', 'POST', 'Content-Type, Authorization'));
        $h = $res->getHeaders();
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('https://app.example.com', $h->getFirst('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, DELETE', $h->getFirst('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $h->getFirst('Access-Control-Allow-Headers'));
        $this->assertSame('600', $h->getFirst('Access-Control-Max-Age'));
        $this->assertSame(null, $h->getFirst('Access-Control-Allow-Credentials'));
        $this->assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $h->getFirst('Vary')
        );
        $this->assertSame(1, count($h->get('Vary')), 'both output streams keep only the last Vary write');
    }

    public function testPreflightWithCredentials(): void {
        $res = $this->run($this->policy(true), $this->preflight('https://app.example.com'));
        $this->assertSame('true', $res->getHeaders()->getFirst('Access-Control-Allow-Credentials'));
    }

    public function testDisallowedOriginPreflightIsForbiddenWithoutCorsHeaders(): void {
        $res = $this->run($this->policy(), $this->preflight('https://evil.io'));
        $this->assertSame(403, $res->getStatus()->getValue());
        $this->assertFalse($res->getHeaders()->contains('Access-Control-Allow-Origin'));
        $this->assertFalse($res->getHeaders()->contains('Access-Control-Allow-Methods'));
    }

    public function testDisallowedMethodPreflightIsForbidden(): void {
        $res = $this->run($this->policy(), $this->preflight('https://app.example.com', 'PUT'));
        $this->assertSame(403, $res->getStatus()->getValue());
        $this->assertFalse($res->getHeaders()->contains('Access-Control-Allow-Origin'));
    }

    public function testDisallowedHeaderPreflightIsForbidden(): void {
        $res = $this->run($this->policy(), $this->preflight('https://app.example.com', 'POST', 'X-Evil'));
        $this->assertSame(403, $res->getStatus()->getValue());
    }

    public function testMalformedRequestedHeaderIsForbiddenEvenWithWildcardHeaders(): void {
        $p = new CorsPolicy(['https://a.io'], ['POST'], ['*']);
        $res = $this->run($p, $this->preflight('https://a.io', 'POST', 'x-ok, bad header'));
        $this->assertSame(403, $res->getStatus()->getValue());
        $res = $this->run($p, $this->preflight('https://a.io', 'POST', 'x-ok'));
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('x-ok', $res->getHeaders()->getFirst('Access-Control-Allow-Headers'));
    }

    // Actual requests: allowed origin gets the headers, always Vary: Origin.
    public function testActualRequestFromAllowedOrigin(): void {
        $res = new ResponseEntity();
        $this->policy(true)->applyToResponse(new CorsStubRequest('POST', ['Origin' => 'https://app.example.com']), $res);
        $h = $res->getHeaders();
        $this->assertSame('https://app.example.com', $h->getFirst('Access-Control-Allow-Origin'));
        $this->assertSame('true', $h->getFirst('Access-Control-Allow-Credentials'));
        $this->assertSame('X-Total-Count', $h->getFirst('Access-Control-Expose-Headers'));
        $this->assertSame('Origin', $h->getFirst('Vary'));
    }

    public function testActualRequestFromOtherOriginGetsNoCorsHeaders(): void {
        $res = new ResponseEntity();
        $this->policy()->applyToResponse(new CorsStubRequest('POST', ['Origin' => 'https://evil.io']), $res);
        $this->assertFalse($res->getHeaders()->contains('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $res->getHeaders()->getFirst('Vary'));
    }

    public function testRequestWithoutOriginIsUntouched(): void {
        $res = new ResponseEntity();
        $this->policy()->applyToResponse(new CorsStubRequest('GET'), $res);
        $this->assertSame([], $res->getHeaders()->getAll());
    }

    private function servlet(?CorsPolicy $global, ?MatchedRequestMapping $found = null): DispatcherServlet {
        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();
        $set = function (string $name, mixed $value) use ($servlet): void {
            $prop = new \ReflectionProperty(DispatcherServlet::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($servlet, $value);
        };
        $ctxData = new ApplicationContextData();
        $ctxData->setPropertyContext($this->props("server:\n  port: 1\n"));
        $set('ctxData', $ctxData);
        $set('corsPolicy', $global);
        $set('mappingRegistry', new CorsStubRegistry($found));
        return $servlet;
    }

    private function route(string $class, string $method): MatchedRequestMapping {
        $ref = RefMethod::getInstance(new \ReflectionMethod($class, $method));
        $mapping = $ref->getAttributes(GetMapping::class)[0]->newInstance();
        $mapping->init($ref);
        return new MatchedRequestMapping($mapping, []);
    }

    private function routePolicy(DispatcherServlet $servlet, MatchedRequestMapping $route): ?CorsPolicy {
        $m = new \ReflectionMethod(DispatcherServlet::class, 'routeCorsPolicy');
        $m->setAccessible(true);
        return $m->invoke($servlet, $route);
    }

    private function global(): CorsPolicy {
        return new CorsPolicy(['https://app.example.com'], ['GET', 'POST'], ['Content-Type'], [], false, 600);
    }

    // No attribute: the route uses the global policy untouched.
    public function testRouteWithoutAttributeUsesGlobalPolicy(): void {
        $global = $this->global();
        $servlet = $this->servlet($global);
        $this->assertSame($global, $this->routePolicy($servlet, $this->route(CorsPlainController::class, 'plain')));
        $this->assertNull($this->routePolicy($this->servlet(null), $this->route(CorsPlainController::class, 'plain')));
    }

    // Class attribute widens origins only; everything else inherits the global policy.
    public function testClassAttributeOverridesOnlyItsFields(): void {
        $servlet = $this->servlet($this->global());
        $p = $this->routePolicy($servlet, $this->route(CorsClassController::class, 'inherits'));
        $this->assertSame('https://partner.io', $p->resolveOrigin('https://partner.io'));
        $this->assertNull($p->resolveOrigin('https://app.example.com'));
        $res = $this->run($p, $this->preflight('https://partner.io', 'POST', 'Content-Type'));
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('600', $res->getHeaders()->getFirst('Access-Control-Max-Age'));
    }

    // Method attribute wins over the class attribute, field by field.
    public function testMethodAttributeBeatsClassAttribute(): void {
        $servlet = $this->servlet($this->global());
        $p = $this->routePolicy($servlet, $this->route(CorsClassController::class, 'overrides'));
        $this->assertSame('https://other.io', $p->resolveOrigin('https://other.io'));
        $this->assertNull($p->resolveOrigin('https://partner.io'));
        $res = $this->run($p, $this->preflight('https://other.io', 'POST', 'Content-Type'));
        $this->assertSame('true', $res->getHeaders()->getFirst('Access-Control-Allow-Credentials'));
        $this->assertSame('60', $res->getHeaders()->getFirst('Access-Control-Max-Age'));
    }

    // Attribute alone enables CORS on a route when the global config is off.
    public function testAttributeEnablesCorsWithoutGlobalConfig(): void {
        $p = $this->routePolicy($this->servlet(null), $this->route(CorsClassController::class, 'inherits'));
        $this->assertTrue($p !== null);
        $this->assertSame('https://partner.io', $p->resolveOrigin('https://partner.io'));
    }

    // Attribute without any origin (own or global) sends nothing: deny by default.
    public function testAttributeWithoutOriginsStaysOff(): void {
        $this->assertNull($this->routePolicy($this->servlet(null), $this->route(CorsNoOriginController::class, 'bare')));
        $this->assertTrue($this->routePolicy($this->servlet($this->global()), $this->route(CorsNoOriginController::class, 'bare')) !== null);
    }

    // "*" + credentials in an attribute fails closed for that route only.
    public function testAttributeWildcardWithCredentialsIsRefused(): void {
        $servlet = $this->servlet($this->global());
        $this->assertNull($this->routePolicy($servlet, $this->route(CorsBadController::class, 'bad')));
    }

    public function testResolveWithoutAttributesReturnsBase(): void {
        $g = $this->global();
        $this->assertSame($g, CorsPolicy::resolve($g));
        $this->assertNull(CorsPolicy::resolve(null));
    }

    // No policy for the asked route (and none global), or not a preflight: normal routing.
    public function testPreflightIsNotInterceptedWithoutAPolicy(): void {
        $route = $this->route(CorsClassController::class, 'inherits');
        $req = $this->preflight('https://partner.io', 'GET');
        $answer = new \ReflectionMethod(DispatcherServlet::class, 'answerPreflight');
        $answer->setAccessible(true);

        // Unknown route + no global policy: not intercepted.
        $this->assertFalse($answer->invoke($this->servlet(null), $req, new ResponseEntity(), 'x', null));
        // Non-preflight: never intercepted.
        $this->assertFalse($answer->invoke(
            $this->servlet($this->global(), $route),
            new CorsStubRequest('GET', ['Origin' => 'https://partner.io']),
            new ResponseEntity(),
            'x',
            null
        ));
    }

    // Configured origin case is normalized: browsers send lowercase and compare bytes.
    public function testResponseOriginIsLowercaseNormalized(): void {
        $p = new CorsPolicy(['https://App.Example.com/']);
        $this->assertSame('https://app.example.com', $p->resolveOrigin('https://app.example.com'));
        $res = new ResponseEntity();
        $p->applyToResponse(new CorsStubRequest('GET', ['Origin' => 'https://app.example.com']), $res);
        $this->assertSame('https://app.example.com', $res->getHeaders()->getFirst('Access-Control-Allow-Origin'));
    }

    // "*" with credentials fails closed on every construction path.
    public function testConstructorRefusesWildcardWithCredentials(): void {
        $this->assertThrows(WinterException::class, fn() => new CorsPolicy(['*'], allowCredentials: true));
    }

    // A controller's Vary survives, and Vary: Origin is added next to it.
    public function testVaryMergesWithControllerValueAndSurvivesEntityMerge(): void {
        $p = $this->policy();
        $req = new CorsStubRequest('GET', ['Origin' => 'https://app.example.com']);
        $res = new ResponseEntity();
        $p->applyToResponse($req, $res);

        // What the dispatcher does with a controller-returned entity.
        $fromController = new ResponseEntity();
        $fromController->withHeader('Vary', 'Accept');
        $res->merge($fromController);
        $this->assertSame('Accept', $res->getHeaders()->getFirst('Vary'));

        $p->applyToResponse($req, $res);
        $this->assertSame('Accept, Origin', $res->getHeaders()->getFirst('Vary'));
        $p->applyToResponse($req, $res);
        $this->assertSame('Accept, Origin', $res->getHeaders()->getFirst('Vary'), 'idempotent');
    }

    // The MCP origin check shares the one normalizer.
    public function testMcpOriginCheckUsesSameNormalization(): void {
        $this->assertTrue(McpController::isAllowedOrigin('HTTPS://A.io/', ['https://a.io']));
        $this->assertFalse(McpController::isAllowedOrigin('https://a.io.evil', ['https://a.io']));
    }

    // A configured "*" method allows any requested method and echoes it back.
    public function testWildcardMethods(): void {
        $p = new CorsPolicy(['https://a.io'], ['*']);
        $res = $this->run($p, $this->preflight('https://a.io', 'PURGE', null));
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('PURGE', $res->getHeaders()->getFirst('Access-Control-Allow-Methods'));
    }

    // Methods are normalized in the constructor, not only in the factories.
    public function testMethodsAreUpperCasedByTheConstructor(): void {
        $p = new CorsPolicy(['https://a.io'], ['get', ' post ']);
        $res = $this->run($p, $this->preflight('https://a.io', 'post', null));
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('GET, POST', $res->getHeaders()->getFirst('Access-Control-Allow-Methods'));
    }

    // Simple requests skip the preflight: the method allowlist applies to them too.
    public function testSimpleRequestWithDisallowedMethodGetsNoAllowOrigin(): void {
        $p = new CorsPolicy(['https://a.io'], ['GET']);
        $res = new ResponseEntity();
        $p->applyToResponse(new CorsStubRequest('POST', ['Origin' => 'https://a.io']), $res);
        $this->assertFalse($res->getHeaders()->contains('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $res->getHeaders()->getFirst('Vary'));

        $res = new ResponseEntity();
        $p->applyToResponse(new CorsStubRequest('GET', ['Origin' => 'https://a.io']), $res);
        $this->assertSame('https://a.io', $res->getHeaders()->getFirst('Access-Control-Allow-Origin'));
    }

    // Padded "*" with credentials fails closed instead of escaping as an exception.
    public function testPaddedWildcardWithCredentialsFromYamlDisablesCors(): void {
        $p = CorsPolicy::fromProperties($this->props(
            "winter:\n  web:\n    cors:\n      allowedOrigins: [' * ']\n      allowCredentials: true\n"
        ));
        $this->assertNull($p);
    }

    // maxAge is capped at one day.
    public function testMaxAgeIsCapped(): void {
        $p = CorsPolicy::fromProperties($this->props(
            "winter:\n  web:\n    cors:\n      allowedOrigins: ['https://a.io']\n      maxAge: 99999\n"
        ));
        $res = $this->run($p, $this->preflight('https://a.io', 'POST', null));
        $this->assertSame('86400', $res->getHeaders()->getFirst('Access-Control-Max-Age'));
    }

    // Several Vary values set by a controller are all kept.
    public function testVaryKeepsEveryExistingValue(): void {
        $res = new ResponseEntity();
        $res->getHeaders()->add('Vary', 'Accept');
        $res->getHeaders()->add('Vary', 'Accept-Encoding');
        $this->policy()->applyToResponse(new CorsStubRequest('GET', ['Origin' => 'https://app.example.com']), $res);
        $this->assertSame('Accept, Accept-Encoding, Origin', $res->getHeaders()->getFirst('Vary'));
        $this->assertSame(1, count($res->getHeaders()->get('Vary')));
    }

    // The preflight answer runs through the real renderer, from the route's policy.
    public function testAnswerPreflightRendersThroughTheRenderer(): void {
        HttpStatus::init();
        $route = $this->route(CorsClassController::class, 'inherits');
        $servlet = $this->servlet(null, $route);
        $appCtx = new class extends WinterApplicationContextBuilder {
            public function __construct() {
            }

            public function getId(): string {
                return 'test';
            }

            public function getApplicationName(): string {
                return 'test';
            }

            public function getApplicationVersion(): string {
                return 'test';
            }

            public function getStartupDate(): int {
                return 0;
            }

            public function beanByClass(string $class): ?object {
                return new DefaultResponseRenderer();
            }
        };
        $prop = new \ReflectionProperty(DispatcherServlet::class, 'appCtx');
        $prop->setAccessible(true);
        $prop->setValue($servlet, $appCtx);

        $res = new ResponseEntity();
        $res->setOutputStream(new BufferedHttpOutputStream());
        $answer = new \ReflectionMethod(DispatcherServlet::class, 'answerPreflight');
        $answer->setAccessible(true);
        $done = $answer->invoke($servlet, $this->preflight('https://partner.io', 'GET', null), $res, 'cors-inherits', null);

        $this->assertTrue($done);
        $this->assertSame(204, $res->getStatus()->getValue());
        $this->assertSame('https://partner.io', $res->getHeaders()->getFirst('Access-Control-Allow-Origin'));
    }
}
