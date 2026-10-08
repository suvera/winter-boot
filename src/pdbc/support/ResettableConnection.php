<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\support;

/**
 * A connection that can be returned to a pool and handed to another
 * request. resetForReuse() must leave no state behind: any open
 * transaction is rolled back. Returns false when the connection is not
 * safe to reuse (the pool then discards it).
 */
interface ResettableConnection {
    public function resetForReuse(): bool;
}
