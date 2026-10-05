# Changelog

## 2.1.1 — 2026-10-05

### Fixed
- Transactions: transaction state is now per coroutine, so concurrent requests no longer join or complete each other's transactions.
- Transactions: `REQUIRES_NEW` and `NOT_SUPPORTED` now run on a separate connection instead of committing or joining the outer transaction.
- Transactions: `@Transactional(noRollbackFor: ...)` now commits instead of leaving the transaction open.
- PDBC: `PdbcTemplate::update()` no longer throws a TypeError on INSERT (generated key under integer index).
- PDBC: pooled connections are rolled back before reuse, so a request can no longer inherit another request's open transaction.
- PDBC: a failed connection checkout inside a coroutine now throws `CannotGetConnectionException` instead of sharing the process connection.
- PDBC: `maxConnections` is no longer exceeded by concurrent checkouts while connections are opening.
- `CoroutineScopedPool` fails closed on create failure instead of handing a coroutine the shared delegate.
- Cache: `InMemoryCache` eviction no longer renumbers integer-like keys (wrong value for wrong key).
- Cache: `InMemoryCache::getOrProvide()` now honours `maximumSize`.
- Cache: default cache keys include the class and every argument value (arrays/objects no longer collide; long keys are hashed, not truncated).
- Cache: `@Cacheable` treats an entry that expires between `has()` and `get()` as a miss instead of returning null.
- Sessions: an unknown or expired session id from the client is replaced with a new id (session fixation).
- Web: the route cache is capped (`MAX_CACHED_PATHS`), so random path values cannot grow memory without bound.
- Web: the `http_request_duration` metric is labelled with the route template, not the raw URI.
- Web: interceptor patterns also match the slash-normalised URI, closing a `//admin` bypass.
- Web: the context path is stripped only on a segment boundary (`api` no longer matches `apiary`).
- Web: a bound `null` argument no longer fails with "Missing parameter".
- Web: a `ControllerInterceptor` veto now renders and runs `afterCompletion()` instead of exiting.
- Binding: static properties are never bound from request data.
- Binding: a `#[JsonProperty]` validator no longer validates the next, unannotated property.
- Binding: invalid-validator error message now names the property.
- RestTemplate: only `http`/`https` URLs are accepted (cURL limited to HTTP protocols).
- RestTemplate: `appendQuery()` keeps existing query keys verbatim and keeps the fragment.
- Migrations: `psql`, `sqlcmd` and `sqlplus` now stop on the first SQL error and report failure.
- Migrations: the CLI runner drains stdout and stderr together (no deadlock on large output).
- `LocalLock` now excludes other holders in the same process (coroutines) and has no stale-lock race.
- KV/queue servers split requests on newline (large or merged writes no longer fail) and compare tokens in constant time.
- KV/queue clients reject undecodable responses with their own exception instead of a TypeError.
- Async: a full in-memory async queue is logged as a dropped job instead of reported as enqueued.
- `RequestMappingRegistry::delete()` now removes the route from lookups.
- `Debug::exceptionBacktrace()` keeps the first stack frame.

### Security
- Actuator `configprops` and `env` mask values whose keys look secret (password, token, key, secret, ...).
- Cache aspects no longer log cache keys or cached values.
- KV/queue clients no longer log raw responses.

### Added
- `RequestSession::regenerateId()` to rotate the session id on login.
- `SessionOptions::$samesite` (default `Lax`) and `samesite` on `HttpCookie`/`ResponseEntity::withCookie()`.
- `winter.security.unserialize.allowedClasses` to restrict classes rebuilt from session, shared cache and shared queue data.
- `winter.task.async.maxConcurrency` to cap concurrent async jobs per worker (default 0 = unlimited).
- `IsolatedConnectionProvider` and `ResettableConnection` interfaces; shared `ScopedConnectionPool` trait for PDO/OCI data sources.
