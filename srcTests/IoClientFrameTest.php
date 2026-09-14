<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\io\kv\KvClient;
use dev\winterframework\io\kv\KvConfig;
use dev\winterframework\io\queue\QueueClient;
use dev\winterframework\io\queue\QueueConfig;
use winterBootTests\Support\TestCase;

/**
 * recv() takes a buffer size in bytes, not a timeout: passing the float
 * timeout used to fatal on PHP 8.5 (and silently capped reads at 5 bytes
 * before that). These tests drive both clients against a stub TCP server
 * that answers in two chunks, proving full newline-frame reassembly.
 */
final class IoClientFrameTest extends TestCase {

    /** @var resource|null */
    private $server = null;

    /**
     * Run $test against a stub server that replies $frame in two chunks.
     */
    private function withStubServer(string $frame, callable $test): void {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertTrue($server !== false, 'stub server listen');
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $pid = pcntl_fork();
        $this->assertTrue($pid !== -1, 'pcntl_fork');

        if ($pid === 0) {
            // Child: serve exactly one request, split mid-frame.
            $conn = @stream_socket_accept($server, 15);
            if ($conn) {
                fgets($conn);
                $mid = (int) (strlen($frame) / 2);
                fwrite($conn, substr($frame, 0, $mid));
                usleep(200000);
                fwrite($conn, substr($frame, $mid) . "\n");
                fclose($conn);
            }
            fclose($server);
            exit(0);
        }

        fclose($server);
        try {
            $test($port);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    public function testKvPutReassemblesChunkedFrame(): void {
        // [status=SUCCESS, error='', data='OK'] split across two TCP chunks.
        $this->withStubServer('[1,"","OK"]', function (int $port): void {
            $client = new KvClient(new KvConfig('tok', $port, '127.0.0.1', null, null, 5.0));
            $this->assertTrue($client->put('kv-space', 'k', 'v'));
        });
    }

    public function testKvGetReturnsChunkedData(): void {
        $this->withStubServer('[1,"","hello"]', function (int $port): void {
            $client = new KvClient(new KvConfig('tok', $port, '127.0.0.1', null, null, 5.0));
            $this->assertSame('hello', $client->get('kv-space', 'k'));
        });
    }

    public function testQueueEnqueueReassemblesChunkedFrame(): void {
        $this->withStubServer('[1,"","OK"]', function (int $port): void {
            $client = new QueueClient(new QueueConfig('tok', $port, '127.0.0.1', null, null, 5.0));
            $this->assertTrue($client->enqueue('q', 'item1'));
        });
    }

    public function testQueueDequeueReturnsChunkedData(): void {
        $this->withStubServer('[1,"","item1"]', function (int $port): void {
            $client = new QueueClient(new QueueConfig('tok', $port, '127.0.0.1', null, null, 5.0));
            $this->assertSame('item1', $client->dequeue('q'));
        });
    }
}
