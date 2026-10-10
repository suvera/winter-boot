# Plan: Remove Swoole from Winter Boot (3.0.0)

> **ABANDONED (2026-10-10).** Swoole is not being removed; Winter Boot stays on Swoole.
> This plan is kept for reference only. Do not use it as the basis for new designs or
> work (for example, `ShmTable`, coroutines and Swoole streaming remain available).

**Status:** Abandoned · ~~Draft for review~~ · **Target release:** ~~`3.0.0`~~ · **Baseline:** PHP 8.6

## 1. Summary

Winter Boot currently depends on the `swoole` extension for its HTTP server, coroutines,
connection pools, timers, background processes, and shared memory. This plan replaces
all of that with **PHP core only**:

- An **embedded HTTP server** written in PHP. Like embedded Tomcat in Spring Boot, the
  application serves HTTP itself. A single `php app.php` runs the whole application.
  You don't need nginx, Apache, PHP-FPM, or any PECL extension.
- **Pre-forked worker processes** for CPU scaling. Each worker handles **one request at a
  time**, so request state is never shared.
- **Forked child processes** for async and scheduled work. The same master process
  supervises them, so the application stays one process tree.

Public framework APIs stay the same: DI, REST, DB, caching, `#[Async]`, `#[Scheduled]`,
KV, queue, and sessions. The `2.x` line stays on Swoole and gets no backports.

This affects **four repositories**: `winter-boot`, the modules in `winter-modules` and
`winter-doctrine`, and the docs in `suvera.github.io`. All of them work on a `3.0.0`
branch (Section 5) and release together.

## 2. Goals and Non-Goals

**Goals**

- No `ext-swoole` requirement anywhere: code, Composer, Docker image, or docs.
- Remove the class of cross-request bugs that came with Swoole: shared state, and
  connections shared between coroutines.
- Keep every public framework API working without changes to user code.
- Performance that is measured, not assumed, against the current Swoole build.

**Non-Goals**

- Building a general-purpose event-loop or async-I/O library.
- Running more than one request at a time inside one worker process.
- HTTP/2 or WebSocket support in 3.0.0. They can be revisited later.
- **TLS termination.** 3.0.0 serves plain HTTP. Use a TLS-terminating proxy or load
  balancer in front. Built-in TLS is a possible later-phase feature (see Section 11).
- Backporting anything to `2.x`.

## 3. Success Criteria

1. `composer.json`, `composer.lock`, `build/docker/Dockerfile`, and the docs no longer
   mention `ext-swoole` or `swoole/ide-helper`. `composer.json` requires `php >= 8.6`.
2. `php app.php` boots and serves HTTP with no web server or FPM in front of it.
3. `php srcTests/run.php` is green on a machine **without** Swoole installed.
4. This grep finds nothing in `src/`:
   `grep -rlE 'Swoole\\|\\Co\\|Coroutine::|SWOOLE_|\bgo\(' src/ --include=*.php`
5. Concurrent requests never share a DB connection or see each other's transactions.
   The PDBC isolation test in Step 3 proves this.
6. `#[Async]`, `#[Scheduled]`, and `DaemonThread` run without Swoole. Their execution
   model is documented.
7. The benchmark gate (Section 9) is recorded and accepted before cutover.
8. `winter-modules` and `winter-doctrine` have no Swoole references, their test suites
   are green on the `3.0.0` core without Swoole, and their `composer.json` require
   `suvera/winter-boot: ^3.0`.

## 4. Architecture

### 4.1 Process model

```
php app.php
 └── master process      binds the listening socket, supervises children, never serves traffic
      ├── http worker 1  accept + poll loop, dispatches one request at a time
      ├── http worker 2
      ├── http worker N  (N = configured worker count)
      ├── async executor 1..N        run #[Async] jobs    (N = winter.task.async.poolSize)
      ├── scheduler                  owns the #[Scheduled] timetable, runs no task code
      │    └── sched executor 1..N   run due tasks        (N = winter.task.scheduling.poolSize)
      └── daemon processes           one per DaemonThread instance (coreSize each)
```

- The **master** binds the socket with `stream_socket_server`, then forks the workers.
  It restarts dead workers, recycles workers after `max_request` requests, and handles
  signals: graceful reload and graceful shutdown with connection draining.
- **HTTP workers** all accept on the shared socket, and the kernel balances
  connections between them. Each worker builds its DI container **once** at startup.
- **Async executors, the scheduler, and daemon processes** are forked and supervised by
  the same master. Section 4.9 describes how work reaches them. Cron-grade jobs may still
  use external cron, but nothing *requires* systemd or supervisor.
- Anything stateful is created **after fork** and never inherited: DB handles, client
  sockets, and especially the `Io\Poll\Context`. The poll RFC gives no fork-safety
  guarantees, and an epoll/kqueue descriptor inherited across `fork()` points at the
  same kernel object in every process. Only the listening socket is inherited on purpose.

### 4.2 Request handling inside an HTTP worker

Each worker has two phases:

1. **I/O phase (non-blocking, many connections).** A loop built on the PHP 8.6 poll API
   (Section 4.7) accepts connections, reads and buffers requests until they are complete, and writes
   finished responses. Slow clients, keep-alive, and timeouts are all handled here.
   A slow client can never block a worker.
2. **Dispatch phase (synchronous, one request).** A complete request becomes a fresh
   `HttpRequest`, goes through the existing `DispatcherServlet`, and produces a
   response. Request-scoped state is created at the start and thrown away at the end.

Because only one request is dispatched at a time, request scope is simply "the current
request". Code needs no coroutine or Fiber IDs, and nothing can leak across requests.
Blocking calls such as PDO and OCI behave the way PHP developers expect.

**Why not concurrent Fibers per connection?** Fibers are cooperative and
single-threaded, and PDO/OCI calls block. A slow query in one Fiber would stall every
other request in that worker, so concurrency would barely improve. In return, every piece
of request state would need Fiber-local scoping, which brings back the bug class this
plan is meant to remove. Fibers stay available for structuring code *inside* a
request, but they are never used to run requests in parallel.

### 4.3 Database connections

- No coroutine-scoped pools. `Channel` and `getCid` keying are removed.
  `CoroutineScopeProvider` and `CoroutineScopedPool` **stay as APIs**, because
  `winter-doctrine` is built on them (Section 4.10). They are backed by a
  **request-scope provider**: a scope is one HTTP request or one async/scheduled job,
  and it ends when that request or job finishes. Swoole-specific providers are deleted.
- Each worker keeps a small **process-local** pool. The current request checks out a
  connection and returns it when the request ends.
- Connection release happens in a `try`/`finally` **in the dispatch frame** (the
  dispatcher, async job runner and scheduler each own one), so it runs on return and on
  exceptions. It must not wait for the end of a PHP request, which never comes in a
  long-lived worker. (The native `deferred()` previously planned for this was removed:
  its Zend Observer hook crashed under Swoole coroutines.)
- Config meaning:
  - `connection.maxConnections` becomes a **per-worker** cap. Size the database so that
    `workers × maxConnections ≤ db max_connections`.
  - `connection.idleTimeout` and `validationQuery` stay, for reaping idle connections
    and pinging on checkout.
  - `connection.persistent` (PDO persistent) stays, with a mandatory reset on checkout:
    no leftover transactions, session variables, or temp tables.

### 4.4 Other Swoole subsystems

| Subsystem | Today (Swoole) | 3.0.0 replacement |
|---|---|---|
| HTTP server | `Swoole\Http\Server` | Embedded server (4.1–4.2) |
| Request context | `Swoole\Coroutine::getContext()` | Plain per-request context object |
| Timers | `Swoole\Timer::tick/after/clear` | Poll-loop timeout drives timers |
| Sleeps | `Co\System::sleep` | Core `sleep`/`usleep` in worker processes |
| Processes | `Swoole\Process`, `SWOOLE_HOOK_ALL` | `pcntl_fork` + master supervision |
| Shared memory | `Swoole\Table`, `Swoole\Atomic` | **Not needed.** Message passing and process-local state (Section 4.8) |
| KV / queue server | `Swoole\Server` TCP daemons | Prefer a Redis backend. Port to streams + poll only if needed |
| KV / queue client | `Swoole\Client` | `stream_socket_client` or Redis |
| HTTP clients | `Swoole\Coroutine\Http\Client` | ext-curl or streams |
| Admin / PID files | `WinterServerAdmin`, `ServerPidManager` | Master signals + health endpoint |

### 4.5 Native extension (`php-ext/winter_boot`)

- The extension may gain native fast paths for the server, such as poll waiting and
  HTTP/1.1 parsing. It stays a single self-contained `.so`: libraries are statically
  linked, licenses are MIT-compatible only (no GPL), and no Swoole code is used.
- **Every native path has a pure-PHP version**, so the framework runs without the
  extension (tests, dev machines).
- **No C code is written before the benchmark gate** shows where the cost actually is.
- The extension currently has no Fiber handling. Add tests for AOP (`zend_execute_ex`
  override) when a Fiber suspends, resumes, or is destroyed while suspended, because
  user code may still use Fibers.

### 4.6 HTTP hardening (now framework code)

Swoole used to handle these. Each one now needs code and a test:

- Read, write, and idle timeouts, keep-alive limits, and a maximum connection count.
- Limits on request line, header count, header size, and body size.
- **Request-smuggling protection:** reject a request that has both `Content-Length`
  and `Transfer-Encoding`, reject duplicate or conflicting `Content-Length` headers,
  and parse chunked bodies strictly.
- Multipart uploads stream to disk without loading the whole body into memory.
- Large responses are streamed.
- Graceful drain on reload and shutdown, plus `max_request` worker recycling
  (`WinterServer.php` already has this setting).
- Access and error logs are sanitized, with no raw bodies, headers, or query values,
  following `DispatcherServlet` conventions.
- **Unknown server settings:** `server.*` keys that only made sense for Swoole must
  **fail loudly at boot** with a clear message. They must not be silently ignored.

### 4.7 PHP 8.6 APIs used

Both RFCs below are **accepted and implemented for PHP 8.6**.

**Poll API** (`Io\Poll` namespace, vote 33–1):

- `Context` (`new Context(Backend::Auto)`) owns the OS poller. `add(Handle, array $events,
  mixed $data): Watcher` registers a handle, and `wait(?int $sec, int $usec, ?int
  $maxEvents): array` returns the triggered `Watcher`s.
- `StreamPollHandle` wraps a PHP stream, for example sockets from `stream_socket_server()`,
  `stream_socket_accept()`, and `stream_socket_client()`.
- `Watcher` has `getData()`, `hasTriggered(Event)`, `modifyEvents()`, and `remove()`.
  Per-connection state goes in `$data`.
- `Event`: `Read`, `Write`, `OneShot`, and `EdgeTriggered` to request; `Read`, `Write`,
  `Error`, `HangUp`, and `ReadHangUp` reported.
- `Backend`: `Auto`, `Epoll`, `Kqueue`, `EventPorts`, `WSAPoll`, `Poll`.
- Errors are thrown as `Io\Poll\PollException` subclasses, which extend `Io\IoException`.

How the framework uses it:

- **Level-triggered (the default) only.** Edge-triggered mode exists only on epoll and
  kqueue, and you have to read until `EAGAIN`, which makes it easy to miss events.
  Revisit after the benchmark if needed.
- **One `Context` per process, created after fork** (Section 4.1).
- **Timers aren't part of the API.** `TimerHandle` is listed only as future scope. The
  loop keeps its own timer heap (keep-alive, read/write deadlines, `IdleCheckRegistry`
  ticks) and passes the time until the next deadline as the `wait()` timeout. This
  replaces `Swoole\Timer`.
- Don't use `ReadHangUp` for correctness. It's reported only by Linux epoll. Detect peer
  close with `HangUp` or a 0-byte read.
- `SocketPollHandle` (for `ext-sockets`) is future scope, so all transports use
  streams, not `ext-sockets`.

**Stream error handling** (vote 25–1):

- Stream context options under `stream`: `error_mode` (`StreamErrorMode::Error` |
  `Exception` | `Silent`), `error_store` (`StreamErrorStore::*`), and `error_handler`.
  Also `stream_last_errors(): StreamError[]` and `stream_clear_errors()`.
- Errors are `StreamError` objects (`code` is a `StreamErrorCode`, plus `message` and
  `terminating`) or a `StreamException` with `getErrors()`.
- **Decision:** the server, IPC, KV/queue, and HTTP-client transports all create their
  streams with an explicit context that uses `error_mode => StreamErrorMode::Exception`.
  A failed accept, read, or write becomes a typed exception the loop can handle per
  connection, not a PHP warning, and nothing raw gets written to logs. These options
  **can't be set on the default context** (`stream_context_set_default()` throws
  `ValueError`), so every `stream_socket_*` / `fopen` call in framework transports must
  pass its own context. `stream_socket_pair()` also takes a context now, which the
  async IPC channel uses.

**Fibers** (PHP 8.1+, unchanged): cooperative and single-threaded. A blocking call in
one Fiber blocks the whole process, which is why Section 4.2 doesn't use Fibers for
request concurrency. A `finally` block inside a suspended Fiber runs only when the Fiber
is resumed or garbage-collected. Cleanup must not depend on a suspended Fiber, and this
case belongs in the connection-release tests (Section 4.5).

### 4.8 Replacing shared memory (`ShmTable` / `WinterTable`)

`ShmTable` (`Swoole\Table`) and `WinterTable` (`Swoole\Atomic`) are internal. The user
docs don't mention them. They have exactly three use sites. In the new process model,
none of them needs memory shared between processes:

| Use site | Who reads and writes it | Replacement |
|---|---|---|
| `AsyncInMemoryQueue`: `#[Async]` jobs from HTTP workers to async workers | Many writers, many readers | **Shared datagram channel**: one message per job (Section 4.9) |
| `ScheduleWorkerProcess` / `ScheduledTaskPoolExecutor`: the schedule table | Filled once at boot, then read and updated only by the scheduler | **Plain PHP array** inside the scheduler process (Section 4.9) |
| `ServerPidManager`: the PID table, read by `DefaultActuatorController` | Written by the master, read occasionally by the actuator endpoint | **Master's own array** (it gets every PID from `pcntl_fork`), plus a small status file written atomically (write to a temp file, then `rename`) for the actuator to read |

**Why this beats APCu (or `shmop`/`sysvshm`):**

- **No new extension.** APCu is a PECL extension. Streams and `pcntl` are already
  required.
- **Performance is the same or better.** An enqueue is one datagram write of a few
  hundred bytes on a local socket, a few microseconds. Today's version takes a `Swoole\Table`
  row lock and copies into fixed-size columns. Nothing here is on the
  per-request hot path except the optional async enqueue.
- **Better behavior:**
  - Jobs are taken in FIFO order. `Swoole\Table` iteration order isn't FIFO.
  - Backpressure replaces silent drops. A full fixed-capacity table rejects jobs; a
    socket that can't take more blocks the writer, or fails it after a short timeout
    with a logged error.
  - No fixed-width columns. The 128/64-byte class and method name limits go away.
    `argSize` stays as a sanity cap (Section 4.9).
  - There are no locks or memory-size settings to tune, and nothing can leak between
    processes.
- **Simpler to reason about.** Each piece of state has one owning process. Others reach
  it by sending messages, not by sharing memory.

**Not durable**, same as today. Async jobs still in flight are lost if the async worker
crashes. Applications that need durable jobs should use the queue module with a
Redis/DB backend, as they should today.

If a future feature really needs counters shared across workers (for example, combined
Prometheus metrics), the default is the same pattern: each worker keeps its own
counters, and the master or exporter collects them over IPC on scrape. No shared memory.

### 4.9 Async, scheduling, and daemon workers

**How it works today:**

- `winter.task.async.poolSize` and `winter.task.scheduling.poolSize` each start N worker
  processes.
- **Async:** each worker has its own queue. `findAvailableWorker()` puts each job on the
  shortest queue.
- **Scheduling:** at boot, each `#[Scheduled]` method is assigned to one worker for good.
- **Inside every worker, jobs run as Swoole coroutines** (`go()`), so one worker runs
  many jobs at once.
- `#[DaemonThread(coreSize: N)]` starts N processes per class. User classes extend
  `ServerWorkerProcess`.

Without coroutines, an executor process runs **one job at a time**. The design below
keeps that predictable and stops a slow job from blocking unrelated work.

**Shared executor pool** (one class, used by both async and scheduling):

- N executor processes, all reading from **one shared Unix datagram channel**
  (`stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0, $ctx)`), created before
  fork.
- Every datagram is one whole job. The kernel delivers each one to exactly one reader,
  so **an idle executor takes the next job**. Load balances itself, and the sender doesn't
  need queue sizes. `findAvailableWorker()` goes away.
- Executors send results and lifecycle messages (`started`, `finished`, `failed`, with
  job ID and PID) on a second channel back to whoever dispatched the job.
- Message size: job arguments keep the existing JSON + `argSize` cap (default 2 KB).
  That's far below the Unix datagram limit, and the IPC queue checks it at boot. An
  oversized job throws `OverflowException` at enqueue, as today.
- Full channel: a non-blocking send that fails is retried for a short configurable
  window, then logged and rejected. Same semantics as today's full table, but no
  silent loss.

**Async (`#[Async]`):**

- HTTP workers send a job datagram to the async pool's channel. One pool,
  `winter.task.async.poolSize` executors.
- `winter.task.async.queueStorage.handler` **stays an extension point.** The datagram queue
  becomes the default `AsyncQueueStore`, replacing `AsyncInMemoryQueue`. Custom handlers
  (for example, Redis-backed) keep working, with executors polling them.
- `NativeAopDriver::bypassOnce()` / `clearBypass()` around the call stay unchanged.

**Scheduling (`#[Scheduled]`):**

- **The scheduler** is one process that holds the timetable as a plain PHP array (the
  rows of today's shared table, including `inProgress`, `nextRun`, and `lastRun`). It
  runs no task code, so a slow task can never delay the clock.
- When a task is due and not `inProgress`, the scheduler marks it `inProgress` and sends
  it to the **scheduling executor pool** (`winter.task.scheduling.poolSize` executors).
  Any free executor runs it. Tasks are no longer pinned to one worker.
- `fixedRate`: `nextRun` is set when the task is sent. `fixedDelay`: `nextRun` is set when
  `finished`/`failed` arrives. Both match today's semantics.
- The scheduler forks and supervises its own executors, so it sees their exits
  directly. The master supervises the scheduler. If an executor dies mid-task, the
  scheduler clears that task's `inProgress`, starts a replacement executor, and the task
  runs again at its next `nextRun`.
- The scheduler's loop sleeps until the earliest `nextRun` (poll timeout) or until an
  executor message arrives.
- Async and scheduling use **separate pools**, so a burst of async jobs can't starve
  scheduled tasks.

**Daemon processes (`#[DaemonThread]`):**

- Each instance gets its own master-supervised process, as today, and is restarted if
  it dies.
- `ServerWorkerProcess` is **user-facing API** (daemon classes extend it). Keep the
  constructor `(WinterServer, ApplicationContext, int $threadId)` and `run()`,
  `getProcessType()`, `getProcessId()`, `getThreadId()`, `getAppCtx()`, and
  `onProcessInvoke()`. `getProcess()` returns `Swoole\Process` today, so it gets a
  framework-owned replacement handle (PID plus signal helpers). Same for anything on
  `getWServer()` that exposes Swoole types. Both are breaking changes for the migration
  guide.

**Behavior change: concurrency per worker.** Today `poolSize: 4` means 4 processes, each
running any number of coroutines. In 3.0 it means **at most 4 jobs at a time** for that
pool. Users with many concurrent or slow (I/O-bound) jobs must raise `poolSize`. The
docs (`async/async-tasks.mdx`, `examples/scheduler-app.mdx`,
`reference/application-yml.mdx`) and the migration guide must say this clearly. The
Step 4 benchmark should include an async-throughput scenario to suggest defaults.

### 4.10 Impact on modules (`winter-modules`, `winter-doctrine`)

Modules use more of the core than the public annotations. They rely on these core APIs:

| Core API | Used by | 3.0 decision |
|---|---|---|
| `WinterServer::addProcess()` | redis queue, kafka, sqs, dtce | **Keep.** It registers a master-supervised process |
| `ServerWorkerProcess` (subclassed) | `RedisQueueWorkerProcess`, `KafkaWorkerProcess`, `SqsWorkerProcess` | **Keep** (Section 4.9). Only `getProcess()` changes |
| `IdleCheckRegistry::register()` | redis, memcache, doctrine | **Keep the API.** Ticks are driven by the event-loop timer heap (Section 4.7) |
| `AsyncQueueStore` (implemented) | `AsyncRedisQueueStore` | **Keep the interface.** See the shared-pool note below |
| `CoroutineScopeProvider(s)`, `CoroutineScopedPool` | doctrine (`DoctrineComponentBuilder`, `MultiTenantManager`, `WinterEntityManager`, `WinterConnection`) | **Keep**, backed by request scope (Section 4.3) |
| `SwooleCoroutineScopeProvider::isAvailable()` | doctrine | **Removed.** Doctrine always uses request scope |
| `WinterServer::addEventCallback('WorkerStart'/'WorkerStop'/'WorkerError'/'Task')`, `addServerArg('task_worker_num')`, `getServerArg('worker_num')`, `getServer()->stop()` | dtce | **Removed** (Swoole internals). Replaced by framework lifecycle events and named executor pools, see below |

Per-module changes:

- **winter-doctrine** (highest risk):
  - `WinterEntityManager`/`WinterConnection` facades keep their design. Today, outside a
    coroutine, they fall back to **one process-wide delegate**. In a long-lived worker
    that means the identity map (and any closed EM) would carry over from one request
    to the next: stale entities, growing memory, and a failed transaction affecting
    later requests.
  - In 3.0 the delegate is **request-scoped**. It's created on first use in a request
    or job, then cleared and released when the scope ends. The DB connection stays in
    the per-worker pool.
  - `winter.coroutine.db.enabled` (`COROUTINE_SCOPED_FLAG`) is retired, because scoping
    is always on.
  - `maxConnections` and `maxWaitMs` become per-worker caps (Section 4.3).
  - Update `PoolCapTest`, `tests/Support/FakeScopes.php`, and the `ext-swoole` suggest
    entry.
  - New test: two sequential requests in one worker don't share entities. A rolled-back
    `wrapInTransaction()` in request 1 doesn't affect request 2.
- **winter-dtce** (biggest rewrite):
  - It runs tasks on Swoole's built-in task workers (`task_worker_num`,
    `onTask`/`onWorkerStart`/`onWorkerError`), stores a worker map in `ShmTable`, and
    stops workers through `getServer()->stop()`.
  - In 3.0 it is rebuilt on the **shared executor pool from Section 4.9**, exposed as a
    small public core API: *named executor pools*, where a module registers a pool name,
    a size, and a job handler. Each DTCE task type gets its own named pool. The `ShmTable`
    worker map becomes the pool's own process bookkeeping. Worker start, stop, and error
    hooks become framework lifecycle events.
  - The persistent storage backends (Redis and others) keep working as they do now.
- **winter-kafka:**
  - The producer's fire-and-forget `go(...)` send becomes a synchronous send (librdkafka
    already buffers internally), with an optional async-pool send for callers that
    don't want to wait.
  - `KafkaWorkerProcess` keeps working through `ServerWorkerProcess`.
- **winter-sqs, winter-s3, winter-opensearch:**
  - Delete both `SwooleHttpHandler` classes (sqs and opensearch) and the S3 → SQS handler
    coupling in `S3Util`.
  - The AWS SDK and the OpenSearch client fall back to their default Guzzle/curl
    handlers. Remove the "Swoole cURL shim" workarounds in `SqsUtil`/`S3Util`.
- **winter-data-redis:**
  - `AsyncRedisQueueStore` uses one Redis list per `workerId`. With the shared executor
    pool it switches to **one shared list** that all async executors read with a
    blocking pop, which load-balances just like the datagram channel.
  - Keep the constructor signature. `workerId` is passed as `0` for the shared store.
  - `PhpRedisTrait`'s "connect after fork" rule stays valid. Update the comment wording.
- **winter-data-memcache:** `IdleCheckRegistry` only. No code change expected.
- **winter-security:** no Swoole usage found.
- **Composer:**
  - `winter-modules` drops `ext-swoole` from `require`. `winter-doctrine` drops it from
    `suggest`.
  - Both raise `php` to `>=8.6` and **add `suvera/winter-boot: ^3.0`**. Today neither
    declares a core dependency, so Composer can't stop someone from mixing 2.x modules
    with the 3.0 core.

## 5. Branches and Release Coordination

The work spans four repositories. Each one gets a **`3.0.0` branch**, and every fix
related to 3.0 goes there:

| Repository | Branch from | What lands on `3.0.0` |
|---|---|---|
| `winter-boot` | current `2.1.0` line | Everything in this plan |
| `winter-modules` | `master` | Module changes from Section 4.10 |
| `winter-doctrine` | `master` | Request-scoped EM/connection, retired flag, tests |
| `suvera.github.io` (`wb-mdx`) | `master` | 3.0 docs, `application-yml.mdx` changes, migration guide |

Rules:

- **Same branch name, `3.0.0`, in every repo.** A fix needed for 3.0 in any repo goes on
  that repo's `3.0.0` branch, never on `master`.
- `2.x` stays on each repo's current default branch, keeps Swoole, and gets no
  backports from `3.0.0`.
- **During development**, modules use the core from its `3.0.0` branch through a
  Composer `path` repository (local) or `"suvera/winter-boot": "3.0.0.x-dev"` with a
  branch alias in core's `composer.json` (CI). The final tags switch to `^3.0`.
- The docs branch is merged only when `3.0.0` is released, so the live site keeps
  showing 2.x docs until then.
- **Release order:** tag `winter-boot 3.0.0` → `winter-doctrine` and `winter-modules`
  `3.0.0` (both require `^3.0`) → merge and publish docs. Each repo bumps its own version
  and changelog (core uses `VERSION.txt` per the release checklist).
- Branches are created when work starts. As the repo rules require, nothing is
  committed, pushed, or tagged without the maintainer's go-ahead.

## 6. Swoole Inventory

Core (`winter-boot`), found with the grep in Section 3 on branch `2.1.0`. For modules,
see Section 4.10.

| Area | Files |
|---|---|
| Bootstrap and server | `src/core/app/WinterWebSwooleApplication.php`, `src/core/context/WinterServer.php` |
| Request context | `src/core/context/WinterApplicationContextBuilder.php` |
| HTTP adapter | `src/core/web/SwooleDispatcherServlet.php`, `src/web/http/SwooleRequest.php`, `src/web/http/SwooleResponseEntity.php`, `src/io/stream/SwooleOutputStream.php` |
| Coroutines | `src/coroutine/SwooleCoroutineScopeProvider.php`, `src/coroutine/CoroutineRunner.php`, `src/coroutine/CoroutineScopedPool.php` (`NullCoroutineScopeProvider` is the model for request scope) |
| DB pools | `src/pdbc/pdo/PdoDataSource.php`, `src/pdbc/oci/OciDataSource.php` |
| Async and scheduling | `src/task/async/AsyncTaskPoolExecutor.php`, `src/task/scheduling/ScheduledTaskPoolExecutor.php`, `src/util/async/AsyncInMemoryQueue.php` |
| Processes | `src/io/process/AsyncWorkerProcess.php`, `AttachableProcess.php`, `ScheduleWorkerProcess.php`, `ServerWorkerProcess.php` |
| Timers | `src/io/timer/IdleCheckRegistry.php` |
| KV and queue | `src/io/kv/KvClient.php`, `KvServer.php`, `KvServerProcess.php`, `src/io/queue/QueueClient.php`, `QueueServer.php` |
| Shared memory | `src/io/shm/ShmTable.php`, `src/core/context/WinterTable.php` |
| HTTP clients | `src/web/client/DefaultRestClientTransport.php` (new in `cd603e3`), `src/migrations/OpenSearchHttpClient.php` |
| Admin | `src/io/server/WinterServerAdmin.php`, `src/io/server/ServerPidManager.php` |
| Comments only | `src/web/session/RequestSession.php` (keep its shape, drop Swoole comments) |
| Tests | `srcTests/swoole/*`, `SwooleRequestTest.php`, `CurrentHttp*Test.php` |
| Build | `build/docker/Dockerfile` (`pecl install swoole`), `composer.json` (`swoole/ide-helper`) |

## 7. Steps

Each step has to pass its validation before the next one starts. All work happens
on the `3.0.0` branch. Following repo rules, changes stay uncommitted until the
maintainer asks for a commit.

### Step 1: Baseline and failing tests first

- Re-run the inventory grep and confirm what to do with each file in Section 6.
- Record `php srcTests/run.php` results with and without Swoole loaded.
- **Write the PDBC isolation test now**, against the Swoole build: two concurrent
  requests, one of which sleeps inside a transaction. It must reproduce the production
  race. Later steps have to make it pass.
- Spike the poll API on a PHP 8.6 build: an accept/read/write echo server with
  `Io\Poll\Context` + `StreamPollHandle`, forked into 2 workers, each creating its own
  `Context`. Check that the API names match Section 4.7 in the released build.

**Validation:** baseline numbers recorded, and the isolation test fails on Swoole as expected.

### Step 2: Embedded server, alongside Swoole (off by default)

- Add a new `WinterWebApplication` with the master, worker model, and two-phase loop
  from Section 4.
- Implement the hardening list in Section 4.6.
- Replace `Swoole\Coroutine::getContext()` in `WinterApplicationContextBuilder` with a
  plain per-request context.
- Leave `WinterWebSwooleApplication` untouched, so both runtimes run the same
  `DispatcherServlet` and test suite.
- Pure PHP only. No native code yet.

**Validation:**
- A hello-world controller is served by plain `php app.php`.
- Slow-client and timeout probes don't block other requests.
- Request-smuggling probes are rejected.
- Worker smoke test with N=2: `kill -9` one worker, the master restarts it, and
  traffic continues.
- The Swoole path is still green.

### Step 3: Request scope and PDBC without coroutines

- Make "one scope = one request" the default (`NullCoroutineScopeProvider` semantics).
- Replace coroutine-scoped pools with the per-worker pool from Section 4.3.
- Back `CoroutineScopeProvider` / `CoroutineScopedPool` with the request-scope provider:
  the dispatcher and job executors open a scope and close it at the end. Keep the
  public API so `winter-doctrine` needs only small changes (Section 4.10). Delete
  `SwooleCoroutineScopeProvider`.

**Validation:**
- The Step 1 isolation test passes on the embedded server.
- PDBC and transaction tests are green.
- Load test (`hey` or `wrk`): no request ever sees another request's transaction or
  session rows.

### Step 4: Benchmark gate

Run the benchmark in Section 9, comparing the Swoole build with the embedded server
from Steps 2–3. Decide on the worker count and whether native fast paths are needed.
**If the numbers can't be accepted, stop and rethink before porting more subsystems.**

### Step 5: Port the remaining subsystems

One slice at a time, behind the existing interfaces:

1. HTTP adapter: retire `SwooleRequest`, `SwooleResponseEntity`,
   `SwooleOutputStream`, and `SwooleDispatcherServlet`.
2. Async, scheduling, and daemons (Section 4.9):
   - Build the shared executor pool and the datagram channel, and make the datagram
     queue the default `AsyncQueueStore` (keep `queueStorage.handler` pluggable).
   - Build the scheduler process: timetable array, dispatch to its executor pool,
     `fixedRate`/`fixedDelay` handling, and recovery when an executor dies.
   - Port `ServerWorkerProcess`/`DaemonThread` off `Swoole\Process`, keeping its
     user-facing API apart from the `getProcess()` replacement.
   - Port `IdleCheckRegistry`, the task executors, and `MonitoringServerProcess`.
   - Tests:
     - N async jobs spread across executors.
     - A slow scheduled task doesn't delay another task.
     - `kill -9` an executor mid-task: `inProgress` is cleared and the task runs at its
       next `nextRun`.
     - An oversized job is rejected at enqueue.
     - A full channel is logged, not silently dropped.
3. KV and queue: Redis backend preferred. Port to streams + poll only where Redis can't
   be used.
4. Shared memory: delete `ShmTable` and `WinterTable`. Move the three use sites to IPC,
   a process-local array, and a master-owned status file (Section 4.8).
5. HTTP clients: `DefaultRestClientTransport` and `OpenSearchHttpClient` move to curl
   or streams.
6. Admin: `WinterServerAdmin`, `ServerPidManager`, and PID files are replaced by
   master signals and a health endpoint.
7. Native fast paths, only if Step 4 showed they are needed.
8. Modules, on each repo's `3.0.0` branch (Section 4.10): winter-doctrine first (it
   shares the request-scope work from Step 3), then data-redis, kafka, sqs/s3/opensearch,
   and dtce last (it needs the named executor pools from slice 2).

**Validation per slice:** focused `srcTests/*Test.php` plus a live smoke test (KV
put/get, queue enqueue/dequeue, a scheduled task fires, an async job runs, an
OpenSearch query, a RestTemplate call). For modules: each repo's test suite is green
against the core's `3.0.0` branch, plus a smoke test for each module (Doctrine CRUD
across two requests, Redis async queue, Kafka produce/consume, an SQS message, an S3
put/get, a DTCE job).

### Step 6: Delete Swoole and cut over

- Remove `ext-swoole` and `swoole/ide-helper`, require `php >= 8.6`, and delete
  Swoole-only classes.
- Remove `ext-swoole` from `winter-modules` and `winter-doctrine`, and add
  `suvera/winter-boot: ^3.0` to both (Section 4.10).
- Ship one `php:8.6-cli-alpine` image that runs the embedded server on the app port.
- Rewrite or archive `srcTests/swoole/` as embedded-server tests.
- Repo rules: run `php -l` on every edited file and get `php srcTests/run.php` green.
- Docs sync in `wb-mdx`: concept pages, plus `reference/application-yml.mdx` for every
  changed `server.*`, `connection.*`, and `winter.task.*` key. Verify with `./build.sh`.
- Add a `CHANGELOG.md` entry and a migration guide from 2.x to 3.0. Cover at least:
  - `poolSize` now means jobs at a time.
  - Scheduled tasks are no longer pinned to a worker.
  - `ServerWorkerProcess::getProcess()` changed.
  - `connection.maxConnections` is now per worker.
  - Retired Swoole `server.*` keys. Set `VERSION.txt` to
  `3.0.0` per the release checklist.

**Validation:** fresh checkouts of the `3.0.0` branches of all four repos, on a machine
without Swoole, pass every suite, serve the example app, and build the docs. Then follow
the release order in Section 5.

## 8. Validation Summary

| Check | When |
|---|---|
| PDBC isolation test (highest risk, the reported production failure) | Written in Step 1, must pass in Step 3 |
| Slow-client, timeout, and request-smuggling probes | Step 2 |
| Worker kill and restart smoke test | Step 2 |
| Benchmark gate | Step 4, before porting the remaining subsystems |
| KV, queue, async, scheduled, and HTTP-client smoke tests | Step 5, per slice |
| AOP behavior with Fibers | Step 5 (native extension slice) |
| No Swoole references in `src/` (Section 3 grep) | Step 6 |
| Module suites green on core `3.0.0`, module smoke tests | Step 5 (slice 8) |
| Doctrine: no entity/EM carry-over between requests | Step 5 (slice 8) |
| Full suite green without Swoole, docs build passes | Step 6 |

## 9. Benchmark Gate

Same app, same machine, fixed concurrency, `wrk` or `hey`:

- Measure **RPS, p50 and p99 latency, and memory per process** for (a) the Swoole build
  and (b) the embedded server.
- Run four scenarios: hello-world, a DB read per request, slow clients mixed with
  normal traffic, and async-job throughput (enqueue rate and completion latency at a
  given `poolSize`).
- Tune the HTTP worker count for each scenario.
- Use the results to decide on native fast paths and the default worker count.
- Cutover goes ahead only with measured numbers. Rollback is the previous release tag
  plus the Swoole image.

## 10. Risks and Open Questions

- **Poll API maturity.** The API is accepted and implemented for 8.6, but it's new.
  Keep it behind one small internal loop class, so a backend bug or a later API change
  only touches one file. Fork behavior isn't documented in the RFC, so the Step 1 spike
  must cover it.
- **Throughput versus Swoole.** One request at a time per worker means concurrency
  equals worker count. A slow DB call occupies a worker, which is the FPM model. The
  benchmark gate decides whether this is acceptable and what the default worker count
  should be.
- **Breaking config.** `server.*` (worker count, Swoole-specific keys),
  `connection.maxConnections` (now per worker), and `winter.task.*.poolSize` (now jobs
  at a time, Section 4.9) change meaning. Every change needs docs and a migration-guide
  entry.
- **Async and scheduling throughput.** Jobs that relied on coroutine concurrency inside
  one worker (many slow HTTP or DB calls) need a larger `poolSize`, which means more
  processes and more memory. The Step 4 async scenario measures this.
- **Sub-minute scheduling and Prometheus metrics.** Combining metrics from many worker
  processes needs an owner in Step 5. The default is per-worker counters collected by the
  master over IPC (Section 4.8).
- **Module compatibility.** Third-party or user modules may call the removed
  `WinterServer` Swoole internals (`addEventCallback`, `getServer()`, `addServerArg`).
  The core should throw a clear error naming the 3.0 replacement, and the migration
  guide should list each one.
- **Cross-repo drift.** Four `3.0.0` branches can fall out of sync. Mitigation: module CI
  installs core `3.0.0.x-dev`, so a core change that breaks a module fails right away.
- **Assumptions:** PHP 8.6 baseline; no new PECL or runtime PHP dependency (no APCu);
  `ext-pcntl` is already required; the native extension never contains Swoole code.

## 11. Later Phases (out of scope for 3.0.0)

- **Built-in TLS termination** (`stream_socket_enable_crypto` in the embedded server).
- HTTP/2 and WebSocket.
- Edge-triggered polling and native fast paths beyond what the Step 4 benchmark justifies.

## 12. Sources

- PHP poll API RFC (implemented, PHP 8.6): https://wiki.php.net/rfc/poll_api
- PHP stream errors RFC (implemented, PHP 8.6): https://wiki.php.net/rfc/stream_errors
- PHP Fibers manual: https://www.php.net/manual/en/language.fibers.php
