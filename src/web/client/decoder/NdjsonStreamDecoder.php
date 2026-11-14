<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\decoder;

use dev\winterframework\web\client\StreamDecoder;
use JsonException;
use LengthException;

/**
 * Newline-delimited JSON (NDJSON / JSON Lines): one JSON document per
 * line, e.g. Ollama's streaming API. Empty lines are skipped; a final line
 * without a newline is delivered when the body ends.
 */
final class NdjsonStreamDecoder extends StreamDecoder {
    public const DEFAULT_MAX_LINE_BYTES = 4 * 1024 * 1024;
    private string $buffer = '';

    public function __construct(private int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES) {
    }

    /**
     * @return list<mixed>
     * @throws JsonException for a line that is not valid JSON
     * @throws LengthException for a line over the cap
     */
    public function feed(string $bytes): iterable {
        $this->buffer .= $bytes;
        $items = [];
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->decodeInto($line, $items);
        }
        if (strlen($this->buffer) > $this->maxLineBytes) {
            throw new LengthException('JSON line exceeds ' . $this->maxLineBytes . ' bytes');
        }
        return $items;
    }

    /** @return list<mixed> */
    public function finish(): iterable {
        $items = [];
        $line = $this->buffer;
        $this->buffer = '';
        $this->decodeInto($line, $items);
        return $items;
    }

    /** @param list<mixed> $items */
    private function decodeInto(string $line, array &$items): void {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        if (strlen($line) > $this->maxLineBytes) {
            throw new LengthException('JSON line exceeds ' . $this->maxLineBytes . ' bytes');
        }
        $items[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
}
