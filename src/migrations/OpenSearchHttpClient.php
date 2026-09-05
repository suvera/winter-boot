<?php

declare(strict_types=1);

namespace dev\winterframework\migrations;

use dev\winterframework\exception\OpenSearchMigrationException;
use dev\winterframework\util\log\Wlf4p;

/**
 * Minimal self-contained HTTP client for talking to OpenSearch during migrations.
 *
 * winter-boot does not depend on opensearch-project/opensearch-php, so this client
 * only uses built-in PHP capabilities:
 *
 *  - Swoole coroutine HTTP client when running inside a Swoole coroutine
 *    (same approach as winter-opensearch SwooleHttpHandler, which exists because
 *    the default cURL based transport breaks under SWOOLE_HOOK_ALL).
 *  - cURL when the curl extension is available (normal CLI case).
 *  - PHP stream wrapper as a last resort.
 *
 * Supported connection config keys (subset of the winter-opensearch module config):
 *  - hosts: list of base urls (required, e.g. ["https://localhost:9200"])
 *  - username / password: basic auth (optional)
 *  - ssl_verification: bool, default true (optional)
 *  - timeout: request timeout in seconds, default 10.0 (optional)
 *  - connect_timeout: connect timeout in seconds, default 5.0 (optional)
 *  - proxy: explicit proxy url, false to disable proxying, null (default)
 *    to leave transport defaults untouched. Honoured by the cURL and stream
 *    transports; the Swoole transport cannot proxy and logs a warning.
 */
class OpenSearchHttpClient {
    use Wlf4p;

    /** @var string[] */
    private array $hosts;
    private ?string $username;
    private ?string $password;
    private bool $sslVerification;
    private float $timeout;
    private float $connectTimeout;
    private string|false|null $proxy;

    public function __construct(array $osConfig) {
        $hosts = $osConfig['hosts'] ?? [];
        if (!is_array($hosts) || empty($hosts)) {
            throw new OpenSearchMigrationException(
                'opensearch-config must have a non-empty "hosts" list'
            );
        }

        $this->hosts = array_values(array_filter(array_map('strval', $hosts)));
        if (empty($this->hosts)) {
            throw new OpenSearchMigrationException(
                'opensearch-config must have a non-empty "hosts" list'
            );
        }

        $this->username = isset($osConfig['username']) ? strval($osConfig['username']) : null;
        $this->password = isset($osConfig['password']) ? strval($osConfig['password']) : null;
        $this->sslVerification = array_key_exists('ssl_verification', $osConfig)
            ? (bool)$osConfig['ssl_verification'] : true;
        $this->timeout = isset($osConfig['timeout']) ? floatval($osConfig['timeout']) : 10.0;
        $this->connectTimeout = isset($osConfig['connect_timeout'])
            ? floatval($osConfig['connect_timeout']) : 5.0;

        if ($this->timeout <= 0) {
            $this->timeout = 10.0;
        }
        if ($this->connectTimeout <= 0) {
            $this->connectTimeout = 5.0;
        }

        $proxy = $osConfig['proxy'] ?? null;
        $this->proxy = ($proxy === false || $proxy === null) ? $proxy : strval($proxy);
    }

    /**
     * Sends one request to the first reachable host.
     *
     * @param array|string|object|null $body Array/object bodies are JSON-encoded
     *   (objects preserve empty `{}`); strings go out verbatim.
     * @return array{status: int, body: mixed} Body is JSON-decoded when possible.
     * @throws OpenSearchMigrationException on transport failure on every host.
     */
    public function request(string $method, string $path, array|string|object|null $body = null): array {
        if (is_array($body) || is_object($body)) {
            $payload = json_encode($body);
            if ($payload === false) {
                throw new OpenSearchMigrationException('Failed to JSON-encode OpenSearch request body');
            }
        } else {
            $payload = $body;
        }

        $lastError = 'no hosts configured';
        foreach ($this->hosts as $host) {
            try {
                return $this->requestSingleHost($method, rtrim($host, '/') . $path, $payload);
            } catch (OpenSearchMigrationException $e) {
                // Transport-level failure: try the next host.
                $lastError = $e->getMessage();
                $this->logWarning("OpenSearch host failed ({$host}): {$lastError}");
            }
        }

        throw new OpenSearchMigrationException(
            'OpenSearch request failed on all hosts. Last error: ' . $lastError
        );
    }

    private function requestSingleHost(string $method, string $url, ?string $payload): array {
        if ($this->useSwoole()) {
            return $this->requestViaSwoole($method, $url, $payload);
        }

        if (function_exists('curl_init')) {
            return $this->requestViaCurl($method, $url, $payload);
        }

        return $this->requestViaStream($method, $url, $payload);
    }

    private function useSwoole(): bool {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine\Http\Client::class)
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    private function basicAuthHeader(): ?string {
        if ($this->username === null || $this->password === null) {
            return null;
        }
        return 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
    }

    private function requestViaSwoole(string $method, string $url, ?string $payload): array {
        if ($this->proxy !== null) {
            $this->logWarning("Swoole HTTP transport does not support proxying, ignoring 'proxy' config");
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new OpenSearchMigrationException("Invalid OpenSearch url: {$url}");
        }

        $ssl = strtolower($parts['scheme'] ?? 'http') === 'https';
        $port = (int)($parts['port'] ?? ($ssl ? 443 : 80));

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $client = new \Swoole\Coroutine\Http\Client($parts['host'], $port, $ssl);
        try {
            $client->set([
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'ssl_verify_peer' => $this->sslVerification,
                'ssl_verify_host' => $this->sslVerification,
            ]);

            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            $authHeader = $this->basicAuthHeader();
            if ($authHeader !== null) {
                [$name, $value] = explode(': ', $authHeader, 2);
                $headers[$name] = $value;
            }
            $client->setHeaders($headers);
            $client->setMethod($method);

            if ($payload !== null && $payload !== '') {
                $client->setData($payload);
            }

            if (!$client->execute($path)) {
                throw new OpenSearchMigrationException(
                    'Swoole HTTP request failed: ' . ($client->errMsg ?: 'unknown error')
                );
            }

            return $this->parseResponse((int)$client->statusCode, (string)($client->body ?? ''));
        } finally {
            $client->close();
        }
    }

    private function requestViaCurl(string $method, string $url, ?string $payload): array {
        $ch = curl_init();
        if ($ch === false) {
            throw new OpenSearchMigrationException('Failed to initialise cURL');
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $authHeader = $this->basicAuthHeader();
        if ($authHeader !== null) {
            $headers[] = $authHeader;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($this->timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)ceil($this->connectTimeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerification);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerification ? 2 : 0);
        if (is_string($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        } elseif ($this->proxy === false) {
            curl_setopt($ch, CURLOPT_PROXY, '');
        }

        if ($payload !== null && $payload !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new OpenSearchMigrationException('cURL request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $this->parseResponse($status, (string)$responseBody);
    }

    private function requestViaStream(string $method, string $url, ?string $payload): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $authHeader = $this->basicAuthHeader();
        if ($authHeader !== null) {
            $headers[] = $authHeader;
        }

        $httpOptions = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ];
        if (is_string($this->proxy)) {
            $httpOptions['proxy'] = $this->proxy;
            $httpOptions['request_fulluri'] = true;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => [
                'verify_peer' => $this->sslVerification,
                'verify_peer_name' => $this->sslVerification,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new OpenSearchMigrationException("Stream request failed for url: {$url}");
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                $status = (int)$m[1];
            }
        }

        return $this->parseResponse($status, (string)$responseBody);
    }

    private function parseResponse(int $status, string $rawBody): array {
        if ($rawBody === '') {
            return ['status' => $status, 'body' => null];
        }

        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['status' => $status, 'body' => $decoded];
        }

        return ['status' => $status, 'body' => $rawBody];
    }
}
