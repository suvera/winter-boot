<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\aop\AopInterceptorRegistry;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\web\client\HttpClientErrorException;
use dev\winterframework\web\client\HttpEntity;
use dev\winterframework\web\client\HttpServerErrorException;
use dev\winterframework\web\client\RestClientException;
use dev\winterframework\web\client\RestClientTransport;
use dev\winterframework\web\client\RestTemplate;
use winterBootTests\Support\TestCase;

final class FakeRestClientTransport implements RestClientTransport {
    /** @var array<int, array{status: int, headers: array<string, string[]>, body: string}> */
    public array $queued = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float, connectTimeout: float}> */
    public array $requests = [];

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'connectTimeout' => $connectTimeout,
        ];
        if ($this->queued === []) {
            throw new \RuntimeException('FakeRestClientTransport: no queued response left.');
        }
        return array_shift($this->queued);
    }

    /** @param array<string, string[]> $headers */
    public function queueJson(int $status, mixed $body, array $headers = []): void {
        $this->queued[] = [
            'status' => $status,
            'headers' => $headers,
            'body' => is_string($body) ? $body : (string)json_encode($body),
        ];
    }
}

final class RestTemplateUserDto {
    public int $id = 0;
    public string $name = '';
}

/**
 * Spring-style RestTemplate: URI templates, JSON mapping, error translation,
 * and default-bean registration so it can be #[Autowired].
 */
final class RestTemplateTest extends TestCase {

    private function template(FakeRestClientTransport $transport): RestTemplate {
        return new RestTemplate($transport);
    }

    public function testGetForObjectDecodesJson(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, ['id' => 7, 'name' => 'ada']);
        $result = $this->template($transport)->getForObject('http://api.local/users/7');
        $this->assertSame(['id' => 7, 'name' => 'ada'], $result);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame('http://api.local/users/7', $transport->requests[0]['url']);
    }

    public function testGetForObjectMapsToClass(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, ['id' => 3, 'name' => 'grace']);
        $result = $this->template($transport)->getForObject(
            'http://api.local/users/3',
            RestTemplateUserDto::class
        );
        $this->assertTrue($result instanceof RestTemplateUserDto);
        $this->assertSame(3, $result->id);
        $this->assertSame('grace', $result->name);
    }

    public function testUriTemplateExpansion(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, ['ok' => true]);
        $this->template($transport)->getForObject(
            'http://api.local/users/{id}/posts/{postId}',
            'array',
            ['id' => 42, 'postId' => 'a b']
        );
        $this->assertSame(
            'http://api.local/users/42/posts/a%20b',
            $transport->requests[0]['url']
        );
    }

    public function testMissingUriVariableThrows(): void {
        $transport = new FakeRestClientTransport();
        $this->assertThrows(
            RestClientException::class,
            fn() => $this->template($transport)->getForObject('http://api.local/users/{id}')
        );
        $this->assertSame([], $transport->requests);
    }

    public function testQueryParamsMergeWithUrlQuery(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, []);
        $this->template($transport)->getForObject(
            'http://api.local/search?kind=user',
            'array',
            [],
            [],
            ['q' => 'ada', 'page' => 2]
        );
        $url = $transport->requests[0]['url'];
        $this->assertTrue(str_starts_with($url, 'http://api.local/search?'));
        $this->assertTrue(str_contains($url, 'kind=user'));
        $this->assertTrue(str_contains($url, 'q=ada'));
        $this->assertTrue(str_contains($url, 'page=2'));
    }

    public function testPostSendsJsonBodyWithContentType(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(201, ['id' => 9]);
        $result = $this->template($transport)->postForObject(
            'http://api.local/users',
            ['name' => 'linus']
        );
        $this->assertSame(['id' => 9], $result);
        $this->assertSame('POST', $transport->requests[0]['method']);
        $this->assertSame('{"name":"linus"}', $transport->requests[0]['body']);
        $this->assertSame(
            'application/json',
            $transport->requests[0]['headers']['Content-Type']
        );
    }

    public function testDefaultHeadersSentOnEveryRequest(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, []);
        $this->template($transport)->getForObject('http://api.local/ping');
        $headers = $transport->requests[0]['headers'];
        $this->assertTrue(isset($headers['User-Agent']));
        $this->assertTrue(str_contains($headers['User-Agent'], 'Chrome/'));
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function testUserAgentCanBeOverridden(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, []);
        $transport->queueJson(200, []);
        $template = $this->template($transport);

        $template->getForObject(
            'http://api.local/ping',
            'array',
            [],
            ['User-Agent' => 'MyApp/1.0']
        );
        $this->assertSame('MyApp/1.0', $transport->requests[0]['headers']['User-Agent']);

        $template->setDefaultHeader('User-Agent', 'OtherApp/2.0');
        $template->getForObject('http://api.local/ping');
        $this->assertSame('OtherApp/2.0', $transport->requests[1]['headers']['User-Agent']);
    }

    public function testPutAndDelete(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, []);
        $transport->queueJson(204, '');
        $template = $this->template($transport);
        $template->put('http://api.local/users/{id}', ['name' => 'x'], ['id' => 5]);
        $template->delete('http://api.local/users/{id}', ['id' => 5]);
        $this->assertSame('PUT', $transport->requests[0]['method']);
        $this->assertSame('http://api.local/users/5', $transport->requests[0]['url']);
        $this->assertSame('DELETE', $transport->requests[1]['method']);
    }

    public function testExchangeWithEntity(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, ['id' => 1], ['X-Trace' => ['abc']]);
        $entity = $template = $this->template($transport)->exchange(
            'POST',
            'http://api.local/echo',
            HttpEntity::withJsonBody(['a' => 1], ['X-Req' => 'yes'])
        );
        $this->assertSame(200, $entity->getStatus()->getValue());
        $this->assertSame(['id' => 1], $entity->getBody());
        $this->assertSame(['abc'], $entity->getHeaders()->get('X-Trace'));
        $this->assertSame('yes', $transport->requests[0]['headers']['X-Req']);
    }

    public function testClientErrorThrows(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(404, ['error' => 'gone']);
        $this->assertThrows(
            HttpClientErrorException::class,
            fn() => $this->template($transport)->getForObject('http://api.local/missing')
        );
    }

    public function testServerErrorThrows(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(500, 'boom');
        try {
            $this->template($transport)->getForObject('http://api.local/flaky');
            throw new \RuntimeException('expected HttpServerErrorException');
        } catch (HttpServerErrorException $ex) {
            $this->assertSame(500, $ex->getStatusCode());
        }
    }

    public function testErrorMessageNeverCarriesQueryString(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(403, 'denied');
        try {
            $this->template($transport)->getForObject('http://api.local/data?token=secret-token');
            throw new \RuntimeException('expected HttpClientErrorException');
        } catch (HttpClientErrorException $ex) {
            $this->assertFalse(str_contains($ex->getMessage(), 'secret-token'));
            $this->assertTrue(str_contains($ex->getMessage(), '403'));
        }
    }

    public function testDisabledThrowReturnsEntityWithRawBody(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(404, 'not here');
        $template = $this->template($transport);
        $template->setThrowOnError(false);
        $entity = $template->getForEntity('http://api.local/missing');
        $this->assertSame(404, $entity->getStatus()->getValue());
        $this->assertSame('not here', $entity->getBody());
    }

    public function testCrlfHeaderValueRejected(): void {
        $transport = new FakeRestClientTransport();
        $this->assertThrows(
            RestClientException::class,
            fn() => $this->template($transport)->getForObject(
                'http://api.local/ping',
                'array',
                [],
                ['X-Evil' => "a\r\nB: c"]
            )
        );
        $this->assertSame([], $transport->requests);
    }

    public function testConfiguredTimeoutsReachTransport(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, []);
        $template = new RestTemplate($transport, timeout: 3.0, connectTimeout: 1.5);
        $template->getForObject('http://api.local/ping');
        $this->assertSame(3.0, $transport->requests[0]['timeout']);
        $this->assertSame(1.5, $transport->requests[0]['connectTimeout']);
    }

    public function testUnknownStatusCodeDoesNotCrash(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(599, 'weird');
        $template = $this->template($transport);
        $template->setThrowOnError(false);
        $entity = $template->getForEntity('http://api.local/weird');
        $this->assertSame(599, $entity->getStatus()->getValue());
        $this->assertSame('weird', $entity->getBody());
    }

    public function testInvalidJsonResponseThrows(): void {
        $transport = new FakeRestClientTransport();
        $transport->queueJson(200, 'not-json{');
        $this->assertThrows(
            RestClientException::class,
            fn() => $this->template($transport)->getForObject('http://api.local/broken')
        );
    }

    public function testInvalidUrlFailsWithoutNetwork(): void {
        $template = new RestTemplate();
        $this->assertThrows(
            RestClientException::class,
            fn() => $template->getForObject('http:///no-host-here')
        );
    }

    public function testDefaultBeanRegisteredByContextBuilder(): void {
        $tmp = sys_get_temp_dir() . '/wb-rest-' . uniqid();
        mkdir($tmp, 0777, true);
        file_put_contents(
            $tmp . '/application.yml',
            "winter:\n    rest:\n        timeout: 3.5\n        connect-timeout: 1.25\n"
        );
        try {
            $ctxData = new ApplicationContextData();
            $ctxData->setPropertyContext(new WinterPropertyContext([$tmp]));
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
            };
            $provider = new WinterBeanProviderContext($ctxData, $appCtx);
            $ctxData->setBeanProvider($provider);
            $ctxData->setAopRegistry(new AopInterceptorRegistry($ctxData, $appCtx));
            foreach (
                [
                    'contextData' => $ctxData,
                    'beanProvider' => $provider,
                    'propertyContext' => $ctxData->getPropertyContext(),
                ] as $prop => $value
            ) {
                $rp = new \ReflectionProperty(WinterApplicationContextBuilder::class, $prop);
                $rp->setAccessible(true);
                $rp->setValue($appCtx, $value);
            }

            // Drive the real registration path, not a copy of it.
            $m = new \ReflectionMethod(WinterApplicationContextBuilder::class, 'registerInternals');
            $m->setAccessible(true);
            $m->invoke($appCtx);

            $bean = $provider->beanByClass(RestTemplate::class);
            $this->assertTrue($bean instanceof RestTemplate);
            $this->assertSame(3.5, $bean->getTimeout());
            $this->assertSame(1.25, $bean->getConnectTimeout());

            // A user-defined RestTemplate bean is never clobbered by the default.
            $custom = new RestTemplate(timeout: 1.0);
            $provider->registerInternalBean($custom, RestTemplate::class);
            $provider->registerInternalBean(new RestTemplate(), RestTemplate::class, false);
            $this->assertTrue($provider->beanByClass(RestTemplate::class) === $custom);
        } finally {
            array_map('unlink', glob($tmp . '/*'));
            rmdir($tmp);
        }
    }
}
