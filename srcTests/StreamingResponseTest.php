<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\web\StreamedResponseWriter;
use dev\winterframework\io\stream\ChunkedOutputStream;
use dev\winterframework\io\stream\InputStream;
use dev\winterframework\io\stream\StringInputStream;
use dev\winterframework\web\http\HttpCookie;
use dev\winterframework\web\sse\SseEmitter;
use dev\winterframework\web\http\StreamingBody;
use dev\winterframework\web\http\StreamingResponseEntity;
use dev\winterframework\web\MediaType;
use winterBootTests\Support\TestCase;

final class RecordingChunkedStream implements ChunkedOutputStream {
    public array $events = [];
    public bool $connected = true;
    public bool $acceptWrites = true;

    public function writeHeader(string $name, ?string $value): void {
        $this->events[] = ['header', $name, $value];
    }

    public function setStatus(int $status, ?string $phrase = null, ?string $version = null): void {
        $this->events[] = ['status', $status];
    }

    /** @param HttpCookie[] $cookies */
    public function setCookies(array $cookies): void {
        $this->events[] = ['cookies', count($cookies)];
    }

    public function writeChunk(string $data): bool {
        if (!$this->acceptWrites) {
            return false;
        }
        $this->events[] = ['chunk', $data];
        return true;
    }

    public function isClientConnected(): bool {
        return $this->connected;
    }

    public function close(): void {
        $this->events[] = ['end'];
    }

    public function write(string|int|float $data, ?int $length = null): int {
        $this->events[] = ['write', (string)$data];
        return strlen((string)$data);
    }

    public function flush(): bool {
        return true;
    }

    public function destroy(): bool {
        return true;
    }

    public function getInputStream(): InputStream {
        return new StringInputStream('');
    }

    public function kinds(): array {
        return array_map(fn(array $e) => $e[0], $this->events);
    }

    public function chunks(): array {
        return array_values(array_map(fn(array $e) => $e[1], array_filter($this->events, fn(array $e) => $e[0] === 'chunk')));
    }
}

class StreamingResponseTest extends TestCase {

    private function writerFor(StreamingResponseEntity $entity, RecordingChunkedStream $stream): StreamedResponseWriter {
        return new StreamedResponseWriter($entity, $stream);
    }

    public function testHeadGoesOutOnceWithTheFirstChunk(): void {
        $entity = StreamingResponseEntity::of(fn() => null, MediaType::TEXT_PLAIN);
        $entity->withContentLength(10);
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor($entity, $stream);

        $this->assertFalse($writer->isCommitted());
        $this->assertTrue($writer->write('a'));
        $this->assertTrue($writer->write('b'));
        $this->assertTrue($writer->isCommitted());
        $writer->end();
        $writer->end();

        $kinds = $stream->kinds();
        $this->assertSame('status', $kinds[0]);
        $this->assertSame(1, count(array_keys($kinds, 'status')), 'status written once');
        $this->assertSame(['a', 'b'], $stream->chunks());
        $this->assertSame(1, count(array_keys($kinds, 'end')), 'ended once');
        $this->assertFalse($writer->write('c'), 'no write after end');
        $this->assertSame(2, $writer->getBytesWritten());
        foreach ($stream->events as $e) {
            if ($e[0] === 'header') {
                $this->assertFalse(strtolower($e[1]) === 'content-length', 'Content-Length stripped');
            }
        }
    }

    public function testEndWithoutWritesStillSendsTheHead(): void {
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor(StreamingResponseEntity::of(fn() => null), $stream);
        $this->assertTrue($writer->write(''), 'empty write sends nothing');
        $this->assertFalse($writer->isCommitted());
        $writer->end();
        $this->assertSame('status', $stream->kinds()[0]);
        $this->assertSame('end', $stream->kinds()[count($stream->events) - 1]);
    }

    public function testFailedWriteMarksTheClientGone(): void {
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor(StreamingResponseEntity::of(fn() => null), $stream);
        $this->assertTrue($writer->write('x'));
        $stream->acceptWrites = false;
        $this->assertFalse($writer->write('y'));
        $this->assertTrue($writer->hasFailed());
        $this->assertFalse($writer->isConnected());
        $stream->acceptWrites = true;
        $this->assertFalse($writer->write('z'), 'stays failed');
    }

    public function testIsConnectedFollowsTheConnectionProbe(): void {
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor(StreamingResponseEntity::of(fn() => null), $stream);
        $this->assertTrue($writer->isConnected());
        $stream->connected = false;
        $this->assertFalse($writer->isConnected());
    }

    public function testEntityDefaults(): void {
        $entity = StreamingResponseEntity::of(fn() => null);
        $this->assertTrue($entity->getBody() instanceof StreamingBody);
        $this->assertSame(MediaType::APPLICATION_OCTET_STREAM, $entity->getHeaders()->getContentType());
        $this->assertSame('no-cache', $entity->getHeaders()->getFirst('Cache-Control'));
        $sse = StreamingResponseEntity::sse(fn() => null);
        $this->assertSame(MediaType::TEXT_EVENT_STREAM, $sse->getHeaders()->getContentType());
    }

    public function testSseFormatting(): void {
        $entity = StreamingResponseEntity::sse(function (SseEmitter $sse): void {
            $sse->event('token', ['text' => "a\nb", 'url' => 'https://x/y'], '7');
            $sse->data("one\r\ntwo\rthree");
            $sse->data(42);
            $sse->comment("note\nsecond");
            $sse->retry(1500);
            $sse->event('done', '[DONE]');
        });
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor($entity, $stream);
        $entity->getBody()->run($writer);
        $writer->end();

        $this->assertSame([
            ": stream open\n\n",
            "event: token\nid: 7\ndata: {\"text\":\"a\\nb\",\"url\":\"https://x/y\"}\n\n",
            "data: one\ndata: two\ndata: three\n\n",
            "data: 42\n\n",
            ": note\n: second\n\n",
            "retry: 1500\n\n",
            "event: done\ndata: [DONE]\n\n",
        ], $stream->chunks());
    }

    public function testSseRejectsLineBreaksInNamesAndIds(): void {
        $emitter = new SseEmitter($this->writerFor(StreamingResponseEntity::of(fn() => null), new RecordingChunkedStream()));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $emitter->event("a\ndata: forged", 'x'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $emitter->event('a', 'x', "1\r"));
    }

    public function testSseStopsWhenTheClientIsGone(): void {
        $stream = new RecordingChunkedStream();
        $calls = 0;
        $entity = StreamingResponseEntity::sse(function (SseEmitter $sse) use (&$calls, $stream): void {
            $calls++;
            $stream->acceptWrites = false;
            $this->assertFalse($sse->data('lost'));
            $this->assertFalse($sse->isConnected());
        });
        $writer = $this->writerFor($entity, $stream);
        $entity->getBody()->run($writer);
        $this->assertSame(1, $calls);
    }

    public function testSseEmitterResendsAParsedEvent(): void {
        // An upstream event parsed with SseParser goes out unchanged: the relay case.
        $parsed = (new \dev\winterframework\web\sse\SseParser())->feed("event: token\nid: 9\nretry: 2000\ndata: a\ndata: b\n\n")[0];
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor(StreamingResponseEntity::of(fn() => null), $stream);
        $emitter = new SseEmitter($writer);
        $this->assertTrue($emitter->send($parsed));
        $this->assertTrue($emitter->send(new \dev\winterframework\web\sse\SseEvent('message', 'plain')));
        $this->assertSame(["event: token\nid: 9\nretry: 2000\ndata: a\ndata: b\n\n", "data: plain\n\n"], $stream->chunks());

        $again = (new \dev\winterframework\web\sse\SseParser())->feed(implode('', $stream->chunks()));
        $this->assertSame(['token', 'a' . "\n" . 'b', '9', 2000], [$again[0]->event, $again[0]->data, $again[0]->id, $again[0]->retry]);
        $this->assertThrows(\InvalidArgumentException::class,
            fn() => $emitter->send(new \dev\winterframework\web\sse\SseEvent("x\ndata: forged", 'y')));
    }

    public function testConditionTokenCancelsWhenTheConditionTurnsTrue(): void {
        $gone = false;
        $token = \dev\winterframework\util\concurrent\CancellationToken::when(function () use (&$gone) { return $gone; }, 'client disconnected');
        $this->assertFalse($token->isCancelled());
        $this->assertSame('', $token->getReason());
        $gone = true;
        $this->assertTrue($token->isCancelled(), 'noticed on the next check, without cancel()');
        $this->assertSame('client disconnected', $token->getReason());
        $gone = false;
        $this->assertTrue($token->isCancelled(), 'stays cancelled');
        $this->assertThrows(\dev\winterframework\util\concurrent\CancelledException::class, fn() => $token->throwIfCancelled());
    }

    public function testWriterTokenCancelsWhenTheClientDisconnects(): void {
        $stream = new RecordingChunkedStream();
        $writer = $this->writerFor(StreamingResponseEntity::of(fn() => null), $stream);
        $token = $writer->getCancellationToken();
        $this->assertSame($token, $writer->getCancellationToken(), 'one token per response');
        $this->assertFalse($token->isCancelled());
        $stream->connected = false;                     // the connection probe reports the drop
        $this->assertTrue($token->isCancelled(), 'without any write');

        $ended = $this->writerFor(StreamingResponseEntity::of(fn() => null), new RecordingChunkedStream());
        $ended->end();
        $this->assertTrue($ended->getCancellationToken()->isCancelled(), 'the stream ended');
    }

    public function testTestResponseWriter(): void {
        $writer = new \dev\winterframework\test\web\TestResponseWriter();
        $entity = StreamingResponseEntity::sse(function (SseEmitter $sse): void {
            $sse->event('token', ['t' => 'a']);
        });
        $entity->getBody()->run($writer);
        $this->assertSame([": stream open\n\n", "event: token\ndata: {\"t\":\"a\"}\n\n"], $writer->getChunks());
        $this->assertTrue($writer->isCommitted());
        $token = $writer->getCancellationToken();
        $writer->disconnect();
        $this->assertFalse($writer->write('x'));
        $this->assertTrue($token->isCancelled());
    }
}
