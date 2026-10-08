<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\aop;

/**
 * Marker for aspects whose commit() failure must reach the caller of the
 * advised method (e.g. a transaction that could not be committed: returning
 * normally would tell the caller the work was saved).
 *
 * Commit failures of other aspects (caching, telemetry, ...) keep the
 * log-and-swallow semantics. Every aspect's commit()/commitFailed() still
 * runs first; then the first failure of a propagating aspect is rethrown.
 */
interface PropagatesCommitFailure {
}
