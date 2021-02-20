<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\types;

abstract class AbstractLob implements Lob {

    public static function valueOf(mixed $stream = null): static {
        return new static($stream);
    }

    public function __construct(
        private mixed $stream = null
    ) {
    }

    public function __toString(): string {
        return '' . $this->getString();
    }

    public function free(): void {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function getStreamResource(): mixed {
        return $this->stream;
    }

    public function setStreamResource(mixed $resource): void {
        $this->stream = $resource;
    }

    public function setString(string $value): void {
        $this->stream = $value;
    }

    public function getString(): ?string {
        return is_resource($this->stream) ? stream_get_contents($this->stream) : $this->stream;
    }

}