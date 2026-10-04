# winter_boot PHP Extension

Native capabilities for PHP, implemented as a Zend Engine extension. No PHP
core patches, no custom PHP build, no external dependencies.

First capability: `deferred()` — Go-like deferred callbacks. Second
capability: native AOP method interception (`winter_boot_advise()`), which
the framework drives — further native capabilities will be added alongside.

## API

```php
deferred(callable $callback): void
defered(callable $callback): void   // alias (common misspelling), identical behavior
```

Registers `$callback` against the **currently executing PHP function**. When
that function exits — normal return, early return, or exception unwinding —
all of its registered callbacks run in **LIFO order** (last registered runs
first). Each callback runs **exactly once**.

```php
function transfer($from, $to, $amount) {
    $lock = acquire($from);
    deferred(function () use ($lock) { release($lock); });

    $log = fopen('/tmp/xfer.log', 'a');
    deferred(function () use ($log) { fclose($log); });

    // ... early returns and throws below still release both ...
}
```

Deferred callbacks run as ordinary calls with their own scope: they see
captured state through the closure `use` list (by value or by reference) and
object references, exactly like any other closure invocation. They do **not**
share the owning function's local symbol table, and they cannot change its
return value.

## Execution semantics

- **Order:** LIFO per owning function. Nested calls and recursion each own
  an independent stack: an inner function's callbacks run when the inner
  function exits, never in the caller.
- **Return paths:** runs on every `return`, including early returns. The
  owner's return value is preserved untouched.
- **Exceptions:** runs during unwinding; the original exception propagates
  unchanged when cleanups succeed.
- **Failing cleanups:** a throwing callback never skips the remaining
  callbacks. Failures are chained through the exception `previous` link so
  nothing is silently dropped (mirroring `finally` supersede-but-preserve
  semantics):
  - no original exception, callbacks threw → the last failure executed
    (earliest registered) is thrown, earlier failures are its `previous`
    chain;
  - original exception plus cleanup failures → the last cleanup failure is
    thrown with the original exception (and earlier failures) chained as
    `previous`.
- **Binding:** always binds to the nearest enclosing *user* function —
  never the caller's caller. Do not wrap `deferred()` in a helper: the
  helper's own frame would own the callback. Dispatchers such as
  `call_user_func()` are transparent (internal frames are skipped to reach
  your function).
- **Fibers:** supported. `Fiber::suspend()` does not trigger callbacks;
  they run once when the fiber's function exits, including on exceptions
  escaping the fiber.
- **Re-entrancy:** a deferred callback may itself call `deferred()`; the new
  callback binds to the callback's own frame and runs when it exits.

## Limitations (fail closed, by design)

| Case | Behavior |
|---|---|
| Global / file / `eval` scope | `deferred()` throws `Error` |
| Non-callable argument | throws `TypeError` |
| Generator functions | `deferred()` throws `Error` — `yield` suspends scope exit, so LIFO-at-exit cannot be honored; use `try`/`finally` instead |
| Fiber destroyed while suspended | pending callbacks for its frames are discarded at request shutdown, never executed |
| Fatal errors (`E_ERROR`, OOM, bailout) | callbacks are **not** executed; remaining entries are freed at request shutdown |
| Abrupt process termination (`SIGKILL`, `exit` in another frame) | callbacks are not executed |
| `exit`/`die` inside the owning function | pending callbacks **do** run (like `finally`), then the exit proceeds |

This is deliberately **not** full Go `defer` compatibility: there are no named
return values to mutate, callbacks cannot alter the return value, and the
fatal-error/abrupt-termination paths above never run callbacks.

## Native AOP interception (second capability)

```php
winter_boot_advise(string $class, string $method): void
winter_boot_is_advised(string $class, string $method): bool
```

Registers a userland class method for VM interception. Each intercepted call
replays the framework's aspect protocol in C by delegating to
`NativeAopDriver::begin()` / `::finish()`:

- `begin()` returns `proceed=false` + `value` to skip the body (the value is
  strictly verified against the declared return type), or `proceed=true` +
  `exCtx`/`interceptor` to run it.
- On body return, `finish($exCtx, $interceptor, 'returned', $value)` runs the
  commit phase; on body throw, `finish(..., 'threw', $throwable)` runs the
  failure phase and the original exception propagates unchanged.

Fail-closed rules: advising abstract methods, constructors, destructors, or
unknown methods throws `Error`; registration is idempotent. Only
methods of the advised bean family intercept (a shared inherited op-array
never matches an unrelated subclass); stale entries can never match a
recycled op-array address (pointer + scope + name are all checked). Advice is
request-bound. Internal (C) functions cannot be advised.

Two engine facts this path depends on: a skipped frame never reaches
`ZEND_RETURN`, so the extension balances the observer `BEGIN` the VM already
issued with an explicit `END`; and `zend_clear_exception()` rewinds the
current opline to the throw bookmark, so the caller's opline is snapshotted
and restored around the failure-phase driver call.

## Compatibility

- PHP **8.5+** (uses the PHP 8.5 Zend Observer init-handler API).
- NTS and ZTS builds; OPcache compatible.
- Coexists with `ext-swoole` (unlike a global `defer()` name would).

## Build & installation

Prerequisites: PHP 8.5+ with development headers (`phpize`, `php-config`).

```sh
cd php-ext
phpize
./configure --enable-winter_boot
make
make install            # or: make test
```

Enable the extension (adjust path as needed):

```ini
extension=winter_boot.so
```

Verify:

```sh
php -d extension=winter_boot.so -r 'function f() { deferred(function () { echo "ok\n"; }); } f();'
```

## Testing

PHPT suite, run with PHP's official test framework (`run-tests.php` is
generated by `phpize`):

```sh
cd php-ext
TEST_PHP_EXECUTABLE=$(command -v php) \
TEST_PHP_ARGS="-n -d extension=$PWD/modules/winter_boot.so" \
php run-tests.php -q tests/
```

(`run_phpt.php` is a dependency-free fallback runner for the same files.)

Coverage: normal/early return, LIFO order, nested calls, recursion,
exception unwinding, throwing cleanups (with and without an original
exception), closure captures (by value / by reference / objects), methods,
nested `deferred()` inside callbacks, fibers, repeated-invocation memory
stability, and fail-closed scope errors.

Memory safety: Valgrind is not available in this environment (no root to
install it). Substitutes used: full suite plus 10k–30k-iteration mixed
(normal/throwing) stress loops under both Zend MM and system malloc
(`USE_ZEND_ALLOC=0`) — no heap errors, flat memory deltas — and OPcache CLI
runs. During development this caught and fixed a real double-free in the
exception-chaining path (`zend_exception_set_previous()` consumes one caller
reference on every return path).

## Benchmark

`benchmark/bench.php` compares native `deferred()` against an equivalent
userland closure stack drained LIFO in `finally`:

```sh
php -n -d extension=$PWD/modules/winter_boot.so benchmark/bench.php 200000
```

Representative result (PHP 8.5 NTS, 200k iterations × 2 cleanups):

- native: ~5.06M ops/s; userland: ~5.53M ops/s — native is ~1.1x userland time.

Performance is intentionally **on par, not faster**: the value of the native
implementation is guaranteed cleanup (exception unwinding, exactly-once,
fail-closed scopes) with zero boilerplate — not speed. Correctness and
predictable cleanup semantics take priority over performance.

## Files

- `winter_boot.c`, `php_winter_boot.h` — extension source
- `config.m4` — build configuration
- `tests/*.phpt` — PHPT test suite (13 tests)
- `run_phpt.php` — minimal PHPT runner (no php-src checkout needed)
- `benchmark/bench.php` — native vs userland benchmark
