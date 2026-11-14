<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\decoder;

use dev\winterframework\web\client\StreamDecoder;

/** Delivers every chunk as it arrives, unchanged. */
final class RawStreamDecoder extends StreamDecoder {

    public function feed(string $bytes): iterable {
        return $bytes === '' ? [] : [$bytes];
    }
}
