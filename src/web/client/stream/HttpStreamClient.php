<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\stream;

use dev\winterframework\web\client\RestClientException;
use dev\winterframework\web\client\RestClientTimeoutException;
use dev\winterframework\web\client\RestTemplate;

/**
 * One streamed HTTP/1.1 exchange over a {@see StreamSocket}: connects
 * (directly or through an HTTP proxy, tunnelling https with CONNECT),
 * upgrades to TLS for https, writes the request, then feeds every read into
 * {@see HttpResponseStreamParser} so the body reaches the caller as it
 * arrives.
 *
 * The request always carries `Connection: close` and
 * `Accept-Encoding: identity`; redirects are not followed. The read timeout
 * applies to each wait for bytes (idle time), never to the whole response.
 * Error messages carry only the method and the query-stripped URL.
 */
final class HttpStreamClient {
    private const PROXY_HEAD_MAX = 16384;

    /**
     * @param string|null $proxy `http://[user:pass@]host:port`; null for a direct connection
     */
    public function __construct(
        private StreamSocket $socket,
        private bool $sslVerification = true,
        private ?string $caFile = null,
        private ?string $proxy = null
    ) {
    }

    /**
     * Runtime-appropriate socket: Swoole's coroutine client inside a
     * coroutine (never blocks the worker), PHP streams elsewhere.
     */
    public static function defaultSocket(): StreamSocket {
        if (extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0) {
            return new CoroutineStreamSocket();
        }
        return new PhpStreamSocket();
    }

    /**
     * @param array<string, mixed> $parts parse_url() of a validated http(s) URL
     * @param array<string, string> $headers request headers (CR/LF already rejected)
     * @param callable(int, array<string, string[]>): void $onResponse
     * @param callable(string): bool $onChunk false aborts
     * @throws RestClientException
     */
    public function execute(
        string $method,
        string $url,
        array $parts,
        array $headers,
        ?string $body,
        float $idleTimeout,
        float $connectTimeout,
        callable $onResponse,
        callable $onChunk
    ): void {
        $method = strtoupper($method);
        $https = strtolower((string)$parts['scheme']) === 'https';
        $host = (string)$parts['host'];
        $connectHost = trim($host, '[]');
        $port = (int)($parts['port'] ?? ($https ? 443 : 80));
        $safeUrl = $method . ' ' . RestTemplate::sanitizeUrl($url);
        $idle = $idleTimeout > 0 ? $idleTimeout : RestTemplate::DEFAULT_TIMEOUT;
        $connect = $connectTimeout > 0 ? $connectTimeout : RestTemplate::DEFAULT_CONNECT_TIMEOUT;

        $proxy = $this->proxy !== null && $this->proxy !== '' ? self::parseProxy($this->proxy) : null;
        $absoluteForm = $proxy !== null && !$https;

        $parser = new HttpResponseStreamParser($onResponse, $onChunk, $method === 'HEAD');
        try {
            try {
                if ($proxy !== null) {
                    $this->socket->connect($proxy['host'], $proxy['port'], $connect);
                    if ($https) {
                        $this->tunnel($proxy, $connectHost, $port, $connect);
                    }
                } else {
                    $this->socket->connect($connectHost, $port, $connect);
                }
                if ($https) {
                    $this->socket->enableTls($connectHost, $this->sslVerification, $this->caFile, $connect);
                }
            } catch (RestClientException $e) {
                $class = str_contains($e->getMessage(), 'timed out')
                    ? RestClientTimeoutException::class : RestClientException::class;
                throw new $class($e->getMessage() . ' for ' . $safeUrl, 0, $e);
            }

            $this->socket->send(self::buildRequest(
                $method, $parts, $host, $port, $https, $headers, $body,
                $absoluteForm ? $proxy : null
            ));

            while (true) {
                $data = $this->socket->recv($idle);
                if ($data === null) {
                    throw new RestClientTimeoutException(
                        'Read timed out after ' . $idle . 's without data for ' . $safeUrl
                    );
                }
                if ($data === '') {
                    $parser->finish();
                    return;
                }
                if (!$parser->feed($data) || $parser->isComplete()) {
                    return;
                }
            }
        } finally {
            $this->socket->close();
        }
    }

    /**
     * @param array{host: string, port: int, auth: ?string} $proxy
     */
    private function tunnel(array $proxy, string $connectHost, int $port, float $timeout): void {
        $authority = (str_contains($connectHost, ':') ? '[' . $connectHost . ']' : $connectHost) . ':' . $port;
        $req = 'CONNECT ' . $authority . " HTTP/1.1\r\nHost: " . $authority . "\r\n";
        if ($proxy['auth'] !== null) {
            $req .= 'Proxy-Authorization: Basic ' . $proxy['auth'] . "\r\n";
        }
        $this->socket->send($req . "\r\n");

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $data = $this->socket->recv($timeout);
            if ($data === null) {
                throw new RestClientException('Proxy CONNECT timed out');
            }
            if ($data === '') {
                throw new RestClientException('Proxy closed the connection during CONNECT');
            }
            $head .= $data;
            if (strlen($head) > self::PROXY_HEAD_MAX) {
                throw new RestClientException('Proxy CONNECT response too large');
            }
        }
        if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m) || $m[1][0] !== '2') {
            throw new RestClientException('Proxy refused CONNECT' . (isset($m[1]) ? ' (' . $m[1] . ')' : ''));
        }
        // Bytes after the CONNECT reply would belong to the TLS handshake; a
        // conforming proxy sends none, so anything extra is refused.
        if (strlen($head) !== strpos($head, "\r\n\r\n") + 4) {
            throw new RestClientException('Proxy sent unexpected data after CONNECT');
        }
    }

    /**
     * @param array<string, mixed> $parts
     * @param array<string, string> $headers
     * @param array{host: string, port: int, auth: ?string}|null $forwardProxy set for plain http through a proxy
     */
    public static function buildRequest(
        string $method,
        array $parts,
        string $host,
        int $port,
        bool $https,
        array $headers,
        ?string $body,
        ?array $forwardProxy = null
    ): string {
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        $defaultPort = $https ? 443 : 80;
        $hostHeader = $host . ($port !== $defaultPort ? ':' . $port : '');
        $target = $forwardProxy !== null ? ($https ? 'https' : 'http') . '://' . $hostHeader . $path : $path;
        // The request line is built by hand: fail closed on anything that
        // could split it (a method or target with spaces or line breaks).
        if (preg_match('/^[A-Z][A-Z0-9_-]*$/', $method) !== 1) {
            throw new RestClientException('Invalid HTTP method');
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/', $target . $hostHeader) === 1) {
            throw new RestClientException('Invalid request target: whitespace or control characters in the URL');
        }

        // Framing headers are owned here; the caller's copies are dropped.
        $reserved = ['host', 'connection', 'content-length', 'transfer-encoding', 'accept-encoding',
            'proxy-authorization', 'proxy-connection', 'te', 'upgrade', 'keep-alive'];
        $lines = [$method . ' ' . $target . ' HTTP/1.1', 'Host: ' . $hostHeader];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string)$name), $reserved, true)) {
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Accept-Encoding: identity';
        $lines[] = 'Connection: close';
        if ($forwardProxy !== null && $forwardProxy['auth'] !== null) {
            $lines[] = 'Proxy-Authorization: Basic ' . $forwardProxy['auth'];
        }
        if ($body !== null && $body !== '') {
            $lines[] = 'Content-Length: ' . strlen($body);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $lines[] = 'Content-Length: 0';
        }
        return implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
    }

    /**
     * @return array{host: string, port: int, auth: ?string}
     * @throws RestClientException for anything but an http:// proxy URL
     */
    public static function parseProxy(string $proxy): array {
        $p = parse_url(str_contains($proxy, '://') ? $proxy : 'http://' . $proxy);
        if ($p === false || empty($p['host']) || strtolower((string)($p['scheme'] ?? 'http')) !== 'http') {
            throw new RestClientException('Unsupported proxy (only http://[user:pass@]host:port)');
        }
        $auth = null;
        if (isset($p['user'])) {
            $auth = base64_encode(rawurldecode((string)$p['user']) . ':' . rawurldecode((string)($p['pass'] ?? '')));
        }
        return ['host' => trim((string)$p['host'], '[]'), 'port' => (int)($p['port'] ?? 8080), 'auth' => $auth];
    }
}
