<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\InMemoryCache;
use dev\winterframework\cache\impl\SharedKvCache;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\web\DispatcherServlet;
use dev\winterframework\core\web\error\DefaultErrorController;
use dev\winterframework\core\web\ResponseRenderer;
use dev\winterframework\exception\HttpRestException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\kv\KvTemplate;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\stereotype\web\RequestBody;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\HttpUploadedFile;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use winterBootTests\Support\TestCase;

final class AuditCoverageFormDto {
    public string $name = '';
    public array $docs = [];
}

final class AuditCoverageFinalService {
}

class AuditCoveragePlainService {
}

final class AuditCoverageStubRequest extends HttpRequest {
    public function __construct(
        private array $stubPost = [],
        private array $stubFiles = [],
        private string $stubBody = '',
        private string $stubContentType = ''
    ) {
    }

    public function getPostParams(): array {
        return $this->stubPost;
    }

    public function getFiles(): array {
        return $this->stubFiles;
    }

    public function getRawBody(): string {
        return $this->stubBody;
    }

    public function getContentType(): string {
        return $this->stubContentType;
    }
}

final class AuditCoverageStubRenderer implements ResponseRenderer {
    public ?ResponseEntity $rendered = null;

    public function render(ResponseEntity $entity, HttpRequest $request): void {
        $this->rendered = $entity;
    }

    public function renderAndExit(ResponseEntity $entity, HttpRequest $request): void {
        $this->rendered = $entity;
    }
}

final class AuditCoverageFakeKv implements KvTemplate {
    public array $store = [];

    public function get(string $domain, string $key): mixed {
        return $this->store[$domain][$key] ?? null;
    }

    public function put(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        $this->store[$domain][$key] = $data;
        return true;
    }

    public function putIfNot(string $domain, string $key, mixed $data, int $ttl = 0): bool {
        if (isset($this->store[$domain][$key])) {
            return false;
        }
        $this->store[$domain][$key] = $data;
        return true;
    }

    public function del(string $domain, string $key): bool {
        unset($this->store[$domain][$key]);
        return true;
    }

    public function has(string $domain, string $key): bool {
        return isset($this->store[$domain][$key]);
    }

    public function ping(): int {
        return 1;
    }

    public function delAll(string $domain): bool {
        unset($this->store[$domain]);
        return true;
    }

    public function incr(string $domain, string $key, int|float|null $incVal = null): int|float {
        return 0;
    }

    public function decr(string $domain, string $key, int|float|null $decVal = null): int|float {
        return 0;
    }

    public function append(string $domain, string $key, string $append): int {
        return 0;
    }

    public function getSet(string $domain, string $key, mixed $value): mixed {
        return null;
    }

    public function getSetIfNot(string $domain, string $key, mixed $data, int $ttl = 0): mixed {
        return null;
    }

    public function strLen(string $domain, string $key): int {
        return 0;
    }

    public function keys(string $domain, string $key): array {
        return [];
    }

    public function getAll(string $domain): array {
        return [];
    }

    public function stats(): array {
        return [];
    }
}

final class AuditCoverageTest extends TestCase {

    private function requestBody(string $type): RequestBody {
        $body = new RequestBody();
        $ref = new \ReflectionClass(RequestBody::class);
        $typeProp = $ref->getProperty('variableType');
        $typeProp->setAccessible(true);
        $typeProp->setValue($body, $type);
        $nameProp = $ref->getProperty('variableName');
        $nameProp->setAccessible(true);
        $nameProp->setValue($body, 'dto');
        return $body;
    }

    private function parseBody(HttpRequest $request, RequestBody $body, string $contentType): mixed {
        $servlet = (new \ReflectionClass(DispatcherServlet::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DispatcherServlet::class, 'parseBody');
        $method->setAccessible(true);
        return $method->invoke($servlet, $request, $body, $contentType);
    }

    // WB-003: form binding comes from the request object, not globals.
    public function testFormBindingIgnoresGlobals(): void {
        $_POST = ['name' => 'mallory'];
        try {
            $request = new AuditCoverageStubRequest(
                ['name' => 'alice'],
                [],
                '',
                MediaType::APPLICATION_FORM_URLENCODED
            );
            $dto = $this->parseBody($request, $this->requestBody(AuditCoverageFormDto::class), MediaType::APPLICATION_FORM_URLENCODED);
            $this->assertSame('alice', $dto->name);
        } finally {
            $_POST = [];
        }
    }

    // WB-003: multipart binding comes from the request object, not globals.
    public function testMultipartBindingIgnoresGlobals(): void {
        $_POST = ['name' => 'mallory'];
        $_FILES = ['docs' => 'evil'];
        try {
            $file = HttpUploadedFile::fromArray([
                'name' => 'a.txt', 'type' => 'text/plain',
                'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 3,
            ]);
            $request = new AuditCoverageStubRequest(
                ['name' => 'alice'],
                ['docs' => [$file]],
                '',
                MediaType::MULTIPART_FORM_DATA . '; boundary=xyz'
            );
            $dto = $this->parseBody($request, $this->requestBody(AuditCoverageFormDto::class), MediaType::MULTIPART_FORM_DATA . '; boundary=xyz');
            $this->assertSame('alice', $dto->name);
            $this->assertSame([$file], $dto->docs);
        } finally {
            $_POST = [];
            $_FILES = [];
        }
    }

    private function errorBody(?\Throwable $t, HttpStatus $status): array {
        HttpStatus::init();
        $controller = new DefaultErrorController();
        $prop = new \ReflectionProperty(DefaultErrorController::class, 'renderer');
        $prop->setAccessible(true);
        $renderer = new AuditCoverageStubRenderer();
        $prop->setValue($controller, $renderer);
        $controller->handleError(new HttpRequest(), new ResponseEntity(), $status, $t);
        return $renderer->rendered->getBody();
    }

    // WB-007: unexpected 500 detail stays server-side.
    public function testErrorControllerHidesUnexpectedDetail(): void {
        $body = $this->errorBody(
            new \RuntimeException('driver failure dsn=mysql:host=db user=root'),
            HttpStatus::$INTERNAL_SERVER_ERROR
        );
        $this->assertSame(500, $body['status']);
        $this->assertNull($body['error']);
    }

    // WB-007: approved HttpRestException values keep status and message.
    public function testErrorControllerKeepsRestException(): void {
        $body = $this->errorBody(
            new HttpRestException(HttpStatus::$NOT_FOUND, 'order missing'),
            HttpStatus::$INTERNAL_SERVER_ERROR
        );
        $this->assertSame(404, $body['status']);
        $this->assertSame('order missing', $body['error']);
    }

    // WB-007: 4xx framework messages are preserved for clients.
    public function testErrorControllerKeepsClientError(): void {
        $body = $this->errorBody(
            new WinterException('Bad Request: Missing parameter id'),
            HttpStatus::$BAD_REQUEST
        );
        $this->assertSame(400, $body['status']);
        $this->assertSame('Bad Request: Missing parameter id', $body['error']);
    }

    // WB-005 gap: KV cache round-trips values through serialization opaquely.
    public function testSharedKvSerializationRoundTrip(): void {
        $kv = new AuditCoverageFakeKv();
        $cache = new SharedKvCache($kv, 'd');
        $cache->put('k', ['a' => 1, 'b' => [1, 2]]);
        $this->assertSame(['a' => 1, 'b' => [1, 2]], $cache->get('k')->get());
        $this->assertTrue(is_string($kv->store['d']['k']));
    }

    // Cache-expiry gap: write TTL is still enforced after the WB-004 fix.
    public function testInMemoryWriteExpiry(): void {
        $cache = new InMemoryCache('t', new CacheConfiguration(expireAfterWriteMs: 80));
        $cache->put('k', 'v');
        $this->assertTrue($cache->has('k'));
        usleep(200000);
        $this->assertFalse($cache->has('k'));
    }

    // WB-015: final beans pass unless an inheritance proxy is required.
    public function testFinalBeanValidation(): void {
        $ctx = (new \ReflectionClass(WinterBeanProviderContext::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(WinterBeanProviderContext::class, 'validateBeanClass');
        $method->setAccessible(true);
        $final = RefKlass::getInstance(AuditCoverageFinalService::class);
        $plain = RefKlass::getInstance(AuditCoveragePlainService::class);
        $method->invoke($ctx, $plain, false);
        $method->invoke($ctx, $plain, true);
        $method->invoke($ctx, $final, false);
        $this->assertThrows(\TypeError::class, function () use ($ctx, $method, $final) {
            $method->invoke($ctx, $final, true);
        });
    }
}
