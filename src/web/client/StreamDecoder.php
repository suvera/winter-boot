<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\web\client\decoder\NdjsonStreamDecoder;
use dev\winterframework\web\client\decoder\RawStreamDecoder;
use dev\winterframework\web\client\decoder\SseStreamDecoder;

/**
 * Turns the bytes of a streamed response into items for the callback of
 * {@see RestTemplate::exchangeStream()}: raw chunks, server-sent events or
 * JSON lines. Bytes arrive in arbitrary pieces (an event or a line may be
 * split across chunks); a decoder buffers what is incomplete and returns
 * only whole items.
 *
 * ```php
 * $rest->exchangeStream('POST', $url, $entity, fn (SseEvent $e) => ..., decoder: StreamDecoder::sse());
 * $rest->exchangeStream('POST', $url, $entity, fn (array $line) => ..., decoder: StreamDecoder::ndjson());
 * ```
 *
 * Decoders keep state: use a new instance for every call. Extend this
 * class for another framing; throw from feed()/finish() for malformed
 * input (RestTemplate reports it as {@see StreamDecodeException}).
 */
abstract class StreamDecoder {

    /**
     * The next piece of the body.
     *
     * @return iterable<mixed> the items completed by it (possibly none)
     */
    abstract public function feed(string $bytes): iterable;

    /**
     * The body ended: items still buffered, if the format allows a last
     * one without a terminator.
     *
     * @return iterable<mixed>
     */
    public function finish(): iterable {
        return [];
    }

    /**
     * True when the stream is logically complete before the connection
     * ends (e.g. the `[DONE]` event): reading stops then.
     */
    public function isDone(): bool {
        return false;
    }

    /** Each chunk as it arrives (string). The default. */
    public static function raw(): RawStreamDecoder {
        return new RawStreamDecoder();
    }

    /**
     * Server-sent events ({@see \dev\winterframework\web\sse\SseEvent}).
     * The `data: [DONE]` sentinel used by AI vendors ends the stream and is
     * not delivered; the last event id stays available for resuming.
     */
    public static function sse(): SseStreamDecoder {
        return new SseStreamDecoder();
    }

    /**
     * Newline-delimited JSON: every non-empty line decoded (objects as
     * arrays). A line longer than $maxLineBytes or invalid JSON fails the call.
     */
    public static function ndjson(int $maxLineBytes = NdjsonStreamDecoder::DEFAULT_MAX_LINE_BYTES): NdjsonStreamDecoder {
        return new NdjsonStreamDecoder($maxLineBytes);
    }
}
