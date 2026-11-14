<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\stream;

use dev\winterframework\web\client\RestClientException;

/**
 * {@see StreamSocket} on PHP stream sockets, for code running outside a
 * Swoole coroutine (CLI commands, plain unit tests). Blocking by nature, so
 * it is never used inside a coroutine.
 */
final class PhpStreamSocket implements StreamSocket {
    /** @var resource|null */
    private $stream = null;

    public function connect(string $host, int $port, float $timeout): void {
        $target = 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $errNo = 0;
        $errStr = '';
        // A fresh context per connection: without one PHP uses the shared
        // default context, and the TLS options set in enableTls() (cafile,
        // peer name) would leak into every later stream in the process.
        $stream = @stream_socket_client(
            $target,
            $errNo,
            $errStr,
            $timeout > 0 ? $timeout : 5.0,
            STREAM_CLIENT_CONNECT,
            stream_context_create()
        );
        if ($stream === false) {
            throw new RestClientException('Connect failed (' . $errNo . ')');
        }
        $this->stream = $stream;
    }

    public function enableTls(string $peerName, bool $verify, ?string $caFile, float $timeout): void {
        $stream = $this->open();
        $options = [
            'peer_name' => $peerName,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
        ];
        if ($caFile !== null && $caFile !== '') {
            $options['cafile'] = $caFile;
        }
        foreach ($options as $name => $value) {
            stream_context_set_option($stream, 'ssl', $name, $value);
        }
        self::applyTimeout($stream, $timeout);
        $ok = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new RestClientException('TLS handshake failed' . ($verify ? ' (certificate verification)' : ''));
        }
    }

    public function send(string $data): void {
        $stream = $this->open();
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($stream, $sent === 0 ? $data : substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new RestClientException('Sending the request failed');
            }
            $sent += $n;
        }
    }

    public function recv(float $timeout): ?string {
        $stream = $this->open();
        $deadline = microtime(true) + ($timeout > 0 ? $timeout : 86400.0);
        while (true) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            self::applyTimeout($stream, $left);
            $data = @fread($stream, 65536);
            if ($data !== false && $data !== '') {
                return $data;
            }
            $meta = stream_get_meta_data($stream);
            if (!empty($meta['timed_out'])) {
                return null;
            }
            if (feof($stream)) {
                return '';
            }
            if ($data === false) {
                throw new RestClientException('Reading the response failed');
            }
            // A TLS record without application data: keep waiting.
        }
    }

    public function close(): void {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return resource */
    private function open() {
        if (!is_resource($this->stream)) {
            throw new RestClientException('Connection is closed');
        }
        return $this->stream;
    }

    /** @param resource $stream */
    private static function applyTimeout($stream, float $seconds): void {
        $seconds = max(0.001, $seconds);
        $whole = (int)floor($seconds);
        stream_set_timeout($stream, $whole, (int)(($seconds - $whole) * 1_000_000));
    }
}
