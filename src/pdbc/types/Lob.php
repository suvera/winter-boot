<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\types;

use Stringable;

interface Lob extends Stringable {
    const BLOB = 1;
    const CLOB = 2;

    public function getType(): int;

    public function free(): void;

    public function getStreamResource(): mixed;

    public function setStreamResource(mixed $resource): void;

    public function setString(string $value): void;

    public function getString(): ?string;
}