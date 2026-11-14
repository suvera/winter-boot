<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\sse;

use dev\winterframework\web\client\RestClientException;

/**
 * One server-sent event, as dispatched by {@see SseParser}.
 */
final class SseEvent {
    public const DONE = '[DONE]';

    /**
     * @param string $event event type (`event:` field), 'message' when absent
     * @param string $data `data:` lines joined with "\n"
     * @param string|null $id last event id seen on the stream (`id:` field)
     * @param int|null $retry reconnection time in ms, when this event set one
     */
    public function __construct(
        public readonly string $event,
        public readonly string $data,
        public readonly ?string $id = null,
        public readonly ?int $retry = null
    ) {
    }

    /**
     * The `data: [DONE]` sentinel AI vendors send to end a stream.
     */
    public function isDone(): bool {
        return trim($this->data) === self::DONE;
    }

    /**
     * Decodes the data as JSON (associative arrays).
     *
     * @throws RestClientException when the data is not valid JSON
     */
    public function json(): mixed {
        try {
            return json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RestClientException('SSE event data is not valid JSON', 0, $e);
        }
    }
}
