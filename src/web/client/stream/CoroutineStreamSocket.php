<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\stream;

use dev\winterframework\web\client\RestClientException;
use Swoole\Coroutine\Client;

/**
 * {@see StreamSocket} on Swoole's coroutine TCP client: every wait yields
 * only the calling coroutine, never the worker, whatever the app's
 * hook_flags (curl may be unhooked). recv() takes a per-call timeout, which
 * is exactly the idle time between chunks.
 */
final class CoroutineStreamSocket implements StreamSocket {
    private const ETIMEDOUT = 110;

    private ?Client $client = null;

    public function connect(string $host, int $port, float $timeout): void {
        $client = new Client(SWOOLE_SOCK_TCP);
        $client->set(['connect_timeout' => $timeout, 'write_timeout' => $timeout]);
        if (!@$client->connect($host, $port, $timeout)) {
            $code = (int)$client->errCode;
            $client->close();
            throw new RestClientException(
                $code === self::ETIMEDOUT ? 'Connect timed out' : 'Connect failed (' . $code . ')'
            );
        }
        $this->client = $client;
    }

    public function enableTls(string $peerName, bool $verify, ?string $caFile, float $timeout): void {
        $client = $this->open();
        $settings = [
            'ssl_host_name' => $peerName,
            'ssl_verify_peer' => $verify,
            'ssl_allow_self_signed' => !$verify,
        ];
        if ($caFile !== null && $caFile !== '') {
            $settings['ssl_cafile'] = $caFile;
        }
        $client->set($settings);
        if (!@$client->enableSSL()) {
            throw new RestClientException(
                'TLS handshake failed' . ($verify ? ' (certificate verification)' : '')
                . ' (' . (int)$client->errCode . ')'
            );
        }
    }

    public function send(string $data): void {
        $client = $this->open();
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @$client->send($sent === 0 ? $data : substr($data, $sent));
            if ($n === false || $n <= 0) {
                throw new RestClientException('Sending the request failed (' . (int)$client->errCode . ')');
            }
            $sent += $n;
        }
    }

    public function recv(float $timeout): ?string {
        $client = $this->open();
        $data = @$client->recv($timeout > 0 ? $timeout : -1);
        if ($data === false) {
            if ((int)$client->errCode === self::ETIMEDOUT) {
                return null;
            }
            throw new RestClientException('Reading the response failed (' . (int)$client->errCode . ')');
        }
        return (string)$data;
    }

    public function close(): void {
        if ($this->client !== null) {
            @$this->client->close();
            $this->client = null;
        }
    }

    private function open(): Client {
        if ($this->client === null) {
            throw new RestClientException('Connection is closed');
        }
        return $this->client;
    }
}
