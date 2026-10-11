# Changelog

## Unreleased

## 2.1.8

### Fixed
- Migrations (`DirectoryScanner::scanForSqlFiles()` / `scanForJsonFiles()`) run version folders in natural order: `1.9.0` before `1.10.0` (a plain string sort ran `1.10.0` first).

### Added
- MySQL Docker image `suvera/winter-boot-mysql`: the runtime image with `pdo_mysql`, `mysqli` and the `mysql` client (for `useCli: true` migrations) in place of `pdo_pgsql`, `pgsql` and `psql`. `build/docker/build.sh` builds all images (`--db=pgsql|mysql` and `--hookall=0|1` narrow it).
- `-hookall` Docker images (`suvera/winter-boot-hookall`, `suvera/winter-boot-mysql-hookall`) for apps running with `hook_flags: SWOOLE_HOOK_ALL`: Swoole is built with native cURL (`curl_multi` crashed the worker under the emulated hook) and, for PostgreSQL, the pgsql coroutine driver, so PDO-pgsql queries no longer block the worker.

## 2.1.7

### Added
- MCP resources: `#[McpResource(uri: ...)]` on a `#[Service]`/`#[Component]` method serves a fixed resource (`config://app`) or a URI template (`site://{domain}/summary/{range}`, placeholders bound to the parameters of the same name) through `resources/list`, `resources/templates/list` and `resources/read`; an optional `listMethod` names the concrete resources for the caller, and `McpResourceNotFoundException` answers "Resource not found" (-32002).
- MCP prompts: `#[McpPrompt]` on a `#[Service]`/`#[Component]` method serves it through `prompts/list` and `prompts/get`; its string parameters are the prompt's arguments, and it returns one user message or a list of `['role', 'text']` messages.
- `McpToolContext::getArguments()` / `getArgument()` and `getOutcome()` (`ok`, `tool_error`, `denied`, `internal`, set before `afterCall()`): the tool call's validated arguments and how it ended, e.g. for audit logs and metrics in a `McpToolInterceptor`.
- `winter.mcp.serverTitle`: sets `serverInfo.title`. `initialize` now advertises resources and prompts when the application has them.

## 2.1.6

### Fixed
- `#[Value]`: configured values (`$env`, `$ini`, yml) and defaults are converted by meaning: `"false"`, `"no"`, `"off"` and `"0"` are `false` for a `bool` (they were `true`), and an `int`/`float` that isn't a number stops startup with an error naming the key, the property and where to fix it, without the value.
- Expected client errors no longer log as server failures: a 4xx `HttpRestException` logs one INFO line (status and route, no stack trace or message), unbindable query/body input logs at DEBUG, and unknown URIs at INFO; server errors still log at ERROR with the trace.
- Request parameter cast errors read `Parameter "limit": Cannot assign a value of type string to type "int"` instead of `Parameter "int" cannot be assigned to "STRING"`, and DTO property errors name the property.
- `#[Transactional]`: the per-call "Transaction started" / "Committing" messages log at DEBUG, and a method's exception is no longer logged with a second stack trace by the aspect (one INFO line says whether the transaction rolled back).
- `#[Lockable]` with the default local lock manager now excludes concurrent requests in the same worker: every caller gets its own lock handle (one shared handle let a second coroutine "acquire" a lock the first one held, so both ran the method).
- `#[Lockable(lockManager: 'beanName')]` accepts a bean name as documented (it failed at startup unless the value was a class name), and `waitMilliSecs` is honored (it was ignored). Waiting yields only the waiting coroutine.
- Lock managers no longer keep an entry for every lock name ever used; the resolved lock name (which can contain argument values) logs at DEBUG, and a guarded method's exception is not logged a second time.

### Added
- Distributed `#[Lockable]` locking: `PdoLockManager` (`dev\winterframework\pdbc\lock`) keeps leases in a database table on an isolated connection, so locks are visible across pods at once, even inside a transaction. `StoreLockManager` + the `LockStore` interface let any shared store back `#[Lockable]` (used by `RedisLockManager` in winter-data-redis and `DbalLockManager` in winter-doctrine).

## 2.1.5

### Added
- MCP tools: `#[McpTool]` on a `#[RestController]` endpoint or a `#[Service]`/`#[Component]` method serves it as a Model Context Protocol tool on `POST <context-path>/mcp` (registered only when a tool exists). Names, input/output schemas and hints are derived from the signature, PHPDoc and HTTP method, and undescribable inputs fail at boot; REST tools run through the normal dispatcher, so interceptors and AOP apply. Optional `winter.mcp.*` keys: `path`, `serverName`, `instructions`, `allowedOrigins`, `maxBodyBytes`.

### Changed
- `DispatcherServlet::dispatch()` restores the previously bound request/response instead of clearing them, and the "end the process after answering" rule is now `HttpRequest::exitsAfterResponse()` (`false` for Swoole and in-process requests). No change for HTTP traffic.

## 2.1.4

### Fixed
- PDO connections closed by the pool (idle timeout, failed reset) now really disconnect. PHP frees a PDO handle only with its last reference, and a `PDOStatement` left in an uncollected reference cycle kept the database session open after the pool had forgotten it, so long-running workers piled up idle sessions until PostgreSQL refused new clients (`sorry, too many clients already`). `PdoConnection::close()` drops the handle before logging (which can yield under Swoole hooks) and collects pending cycles.

## 2.1.2

### Added
- Worker lifecycle hooks: `#[OnWorkerStart]` / `#[OnWorkerStop]` on a `#[Component]`/`#[Service]` implementing `WorkerStartEvent` / `WorkerStopEvent` run in every HTTP and task worker as it starts or stops.
- `winter.web.json.prettyPrint` (default `false`): set `true` to render JSON responses indented instead of compact. Responses were always indented before; the default is now compact.

### Changed
- Default database pool cap (`winter.coroutine.db.maxConnections` / `connection.maxConnections`) lowered from 50 to 10 per worker, so a few workers stay under PostgreSQL's default `max_connections` of 100.
- `#[Async]` enqueue/processing messages log at `DEBUG` instead of `INFO`.
- KV and queue store clients: inside a coroutine each call now uses its own non-blocking `Swoole\Coroutine\Client` connection (idle ones are reused) instead of one blocking per-worker socket; the undocumented `$client` magic property and the `connect()`/`recvFrame()` methods are gone.

### Fixed
- Actuator `/health` answers 503 when the aggregated status is `DOWN` or `OUT_OF_SERVICE`, so probes and load balancers that read only the status code see the failure.
- Prometheus: `incr`/`incrBy`/`decr`/`decrBy`/`observe`/`startTimer` initialise the registry, so `http_request_duration` and app metrics recorded before a worker's first scrape are no longer dropped.
- Startup no longer aborts when a scanned namespace directory is missing (e.g. an empty folder git did not keep); it is skipped with a warning.
- HTTP headers: name lookups are case-insensitive, so `getFirstHeader('user-agent')` finds the `User-Agent` header (and `#[RequestParam(source: 'header')]` binds regardless of case); adding a name that differs only in case appends to the same header.
- KV and queue store clients: concurrent coroutines in one worker no longer block the worker or read each other's replies from the shared socket.
- Routes: literal path segments may contain dots (`/robots.txt`, `/assets/snow.min.js`); `.` and `..` segments stay rejected.
- `server.port` (and `server.address`) set from `$env.X` are converted to the types Swoole needs, so a port from the environment no longer fails startup; `server.swoole.*` values that are integer or `true`/`false` strings get native types.
- Startup banner: the Winter Boot version now comes from the installed Composer release tag when there is one, falling back to `VERSION.txt`; releases up to 2.1.0 shipped a stale `VERSION.txt` and showed `1.0.0-dev`.

## 2.1.1

### Changed
- Native extension: its version (`phpversion('winter_boot')`, `php --ri winter_boot`) now comes from the repo-root `VERSION.txt` at `configure` time instead of a hardcoded `PHP_WINTER_BOOT_VERSION`, so it always matches the framework version.

### Removed
- Native extension: `deferred()` / `defered()` are removed. Their Zend Observer hook left `EG(current_observed_frame)` pointing into freed coroutine stacks whenever a Swoole coroutine yielded (Swoole only carries observer state across switches with `swoole.enable_fiber_mock=1`), crashing PHP at shutdown. Use `try`/`finally` for cleanup. The extension now registers no observer handlers.

### Fixed
- OpenSearch migrations: connections read from module config files (e.g. `opensearch-config.yml`) now resolve `$env.X`, `$ini.key` and `a || b` expressions like module configs at app boot; previously the raw strings were used (literal `$ini.*` credentials, and `"$env.X || false"` read as a truthy `ssl_verification`). An unresolvable reference now fails the migration.
- Native extension: values supplied by `stopExecution()` (e.g. a `#[Cacheable]` hit) are verified against nullable, union, DNF, `callable`, `iterable` and `static` return types correctly. Before, `?Dto` rejected a `Dto`, `array|ResponseEntity` rejected both, `int|Foo|Bar` rejected an int, `string|false` accepted `true`, and a DNF member accepted any value.
- Native extension: a skipped call (stop value, `begin()` throwing, or a rejected value) no longer corrupts the heap, leaks its arguments, or leaves the engine pointing at the dead frame. The last could make the caller loop forever on its next exception.
- AOP: `#{param}` templates on controller endpoints (e.g. `#[Lockable(name: "order-#{id}")]`) now resolve the argument; aspects on endpoints received name-keyed arguments and read them by position, so every request shared one lock / cache key. Endpoint aspects now get arguments in declaration order, like every other AOP call; this changes the `@Cacheable` keys of controller endpoints once.
- AOP: when an aspect's `begin()` fails, the aspects already begun each get `beginFailed()` with their own context and the original exception; previously the failing aspect was called once per context and a throwing handler replaced the exception for the rest. A throwing `failed()` handler no longer replaces the exception for later aspects either.
- AOP: an AOP attribute on a `#[RestController]` method that is not a request-mapped endpoint now logs a startup warning; such advice never runs because controllers are not proxied.
- Testing: added `IdleCheckRegistry::disableTimer()` so a test bootstrap that builds an application context without starting the server can stop the datasource idle-check timer; otherwise the pending Swoole timer keeps the process alive after the last test (see the user-docs testing page).
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
- Native AOP: sibling beans that inherit the same advised method are all intercepted (the last bean built no longer silently disables the others' aspects).
- Native AOP: advised calls report the bean class, not the declaring parent, so inherited methods find their interceptor and `#[Async]` jobs resolve the right bean.
- PDBC: a coroutine woken by a released connection now takes it instead of opening another, so `maxConnections` is no longer exceeded.
- PDBC: `REQUIRES_NEW`/`NOT_SUPPORTED` connections count against `maxConnections`, and ending them wakes waiting coroutines. A nested one (the caller already holds a connection) never waits for a slot, so concurrent callers cannot deadlock on the pool.
- PDBC: an idle connection no longer admits a new request while the pool is at `maxConnections`.
- PDBC: `PoolExhaustedException` names the real settings (`winter.coroutine.db.maxConnections` / `connection.maxConnections`) instead of a Doctrine key.
- Beans: concurrent first requests no longer fail with a false "beans form a cycle" error when a bean's construction yields (e.g. a DataSource opening a hooked connection); the second request waits for the first build.
- Beans: a bean whose construction failed can be built again on the next lookup instead of reporting a dependency cycle forever.
- Transactions: `REQUIRES_NEW`/`NOT_SUPPORTED` on a custom DataSource without `IsolatedConnectionProvider` run on the shared connection again (with a warning) instead of throwing.
- RestTemplate: `HEAD` requests over cURL no longer wait for a body until the timeout.
- RestTemplate: a custom error handler that returns now gets the raw string body instead of a JSON-decode failure.
- `RequestMappingRegistry::delete()` of a concrete path no longer also removes the template route it matches.
- Web: routed requests no longer fail with a 500 `TypeError` in `DispatcherServlet::missingParameters()` (it now accepts `RefMethod`).
- Server: event callbacks are grouped case-insensitively, so a module's `WorkerStart` no longer replaces the framework's `workerStart` handler (workers were never registered for shutdown).
- Server: workers and the manager stay in the terminal's process group, so Ctrl+C stops every process instead of leaving workers running.

### Security
- Native extension: `winter_boot_expand_template()` now caps each *growing* substitution pass at 16 MiB and throws an `Error` instead of allocating past it. Chained pairs (a placeholder expanding to many copies of a later placeholder) grew the result geometrically from tiny input, so a small set of AOP `#{...}`/`${...}` values could exhaust memory and abort the request. Same-length and shrinking passes are never capped, and the size is checked before the multiply so it can no longer integer-overflow into an under-allocated buffer. AOP name/key expansion that hits the cap now surfaces as `AopException` with the target class, like inline-code errors.
- Actuator `configprops` and `env` mask values whose keys look secret (password, token, key, secret, ...).
- Cache aspects no longer log cache keys or cached values.
- KV/queue clients no longer log raw responses.
- Sessions: `destroy()` after `regenerateId()` also deletes the pre-rotation session row, so the old id cannot stay logged in.

### Added
- `server.swoole.hook_flags` accepts `SWOOLE_HOOK_*` names, a list of names (OR'ed) and `-NAME` removals besides a number; unknown names fail startup. Default unchanged (absent = Swoole's 0, no runtime hooks).
- `RequestSession::regenerateId()` to rotate the session id on login.
- `SessionOptions::$samesite` (default `Lax`) and `samesite` on `HttpCookie`/`ResponseEntity::withCookie()`.
- `winter.security.unserialize.allowedClasses` to restrict classes rebuilt from session, shared cache and shared queue data.
- `winter.task.async.maxConcurrency` to cap concurrent async jobs per worker (default 0 = unlimited).
- `IsolatedConnectionProvider` and `ResettableConnection` interfaces; shared `ScopedConnectionPool` trait for PDO/OCI data sources.
