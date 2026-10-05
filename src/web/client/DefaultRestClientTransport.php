<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * Default {@see RestClientTransport}: picks the HTTP engine at runtime.
 *
 *  - Inside a Swoole coroutine it uses the coroutine HTTP client (non-blocking
 *    within the worker; a blocking engine would stall the whole worker).
 *    URL handling there is ported from the classic coroutine client pattern:
 *    explicit SSL/port defaulting, path/query splitting, errCode/errMsg
 *    mapping and close() in a finally block.
 *  - Otherwise cURL when the extension is available (normal CLI case).
 *  - Plain PHP streams as a last resort, so the client works everywhere.
 *
 * The engine choice is a private runtime detail: no engine name appears in
 * any public class or method, so callers (and future refactors) never depend
 * on which one is underneath.
 */
class DefaultRestClientTransport implements RestClientTransport {
    /**
     * @param string|false|null $proxy explicit proxy url, false to disable
     *   proxying, null (default) to leave transport defaults untouched.
     *   Honoured by the cURL and stream engines; the coroutine engine cannot
     *   proxy and ignores it.
     */
    public function __construct(
        private bool $sslVerification = true,
        private string|false|null $proxy = null
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new RestClientException(
                'Invalid request url: ' . RestTemplate::sanitizeUrl($url)
            );
        }
        // Only http(s): file://, gopher://, php:// ... would turn a URL taken
        // from input into local file reads or SSRF against internal services.
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RestClientException(
                'Unsupported url scheme (only http/https): ' . RestTemplate::sanitizeUrl($url)
            );
        }
        foreach ($headers as $name => $value) {
            self::assertNoCrlf((string)$name, (string)$value);
        }

        if ($this->useCoroutineClient()) {
            return $this->requestViaCoroutine($method, $parts, $headers, $body, $timeout, $connectTimeout);
        }

        if (function_exists('curl_init')) {
            return $this->requestViaCurl($method, $url, $headers, $body, $timeout, $connectTimeout);
        }

        return $this->requestViaStream($method, $url, $headers, $body, $timeout, $connectTimeout);
    }

    private static function assertNoCrlf(string $name, string $value): void {
        if (str_contains($name, "\r") || str_contains($name, "\n")
            || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RestClientException('Invalid header: header names and values must not contain CR/LF');
        }
    }

    private function useCoroutineClient(): bool {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine\Http\Client::class)
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /** @param array<string, mixed> $parts */
    private function requestViaCoroutine(
        string $method,
        array $parts,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array {
        $ssl = strtolower((string)($parts['scheme'] ?? 'https')) === 'https';
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? ($ssl ? 443 : 80));

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        try {
            $client->set([
                'timeout' => $timeout > 0 ? $timeout : RestTemplate::DEFAULT_TIMEOUT,
                'connect_timeout' => $connectTimeout > 0
                    ? $connectTimeout : RestTemplate::DEFAULT_CONNECT_TIMEOUT,
                'ssl_verify_peer' => $this->sslVerification,
                'ssl_verify_host' => $this->sslVerification,
            ]);
            $client->setHeaders($headers);
            $client->setMethod(strtoupper($method));
            if ($body !== null && $body !== '') {
                $client->setData($body);
            }

            if (!$client->execute($path)) {
                $errMsg = $client->errMsg ?: 'HTTP request failed';
                throw new RestClientException($errMsg . ' (' . intval($client->errCode) . ')');
            }

            $responseHeaders = [];
            foreach ($client->headers ?? [] as $name => $value) {
                $responseHeaders[(string)$name] = is_array($value)
                    ? array_map('strval', $value) : [strval($value)];
            }

            return [
                'status' => intval($client->statusCode),
                'headers' => $responseHeaders,
                'body' => strval($client->body ?? ''),
            ];
        } finally {
            $client->close();
        }
    }

    /** @param array<string, string> $headers */
    private function requestViaCurl(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            throw new RestClientException('Failed to initialise cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($timeout > 0 ? $timeout : RestTemplate::DEFAULT_TIMEOUT));
        curl_setopt(
            $ch,
            CURLOPT_CONNECTTIMEOUT,
            (int)ceil($connectTimeout > 0 ? $connectTimeout : RestTemplate::DEFAULT_CONNECT_TIMEOUT)
        );
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerification);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerification ? 2 : 0);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders): int {
            $len = strlen($line);
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if ($name !== '') {
                    $responseHeaders[$name][] = $value;
                }
            }
            return $len;
        });
        if (is_string($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        } elseif ($this->proxy === false) {
            curl_setopt($ch, CURLOPT_PROXY, '');
        }
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RestClientException('HTTP request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$responseBody];
    }

    /** @param array<string, string> $headers */
    private function requestViaStream(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        // Streams expose a single timeout, so bound the whole call by the
        // larger of the two budgets.
        $effectiveTimeout = max(
            $timeout > 0 ? $timeout : RestTemplate::DEFAULT_TIMEOUT,
            $connectTimeout > 0 ? $connectTimeout : RestTemplate::DEFAULT_CONNECT_TIMEOUT
        );
        $httpOptions = [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'timeout' => $effectiveTimeout,
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

        // Streams expose a single timeout: the connect timeout bounds dialling
        // and the overall budget is enforced per read by the wrapper.
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RestClientException(
                'HTTP request failed for url: ' . RestTemplate::sanitizeUrl($url)
            );
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                $status = (int)$m[1];
                continue;
            }
            $pos = strpos($headerLine, ':');
            if ($pos !== false) {
                $name = trim(substr($headerLine, 0, $pos));
                $value = trim(substr($headerLine, $pos + 1));
                if ($name !== '') {
                    $responseHeaders[$name][] = $value;
                }
            }
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$responseBody];
    }
}
