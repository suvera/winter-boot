# winter_boot PHP Extension

Native capabilities for PHP, implemented as a Zend Engine extension. No PHP
core patches, no custom PHP build, no external dependencies.

Capabilities: native AOP method interception (`winter_boot_advise()`), which
the framework drives; `#{...}` template evaluation
(`winter_boot_exec_inline()`, with a compilation cache), so no `eval()`
remains in PHP code; and single-call template substitution
(`winter_boot_expand_template()`) for cache/lock key building.

The extension registers no Zend Observer handlers. (An earlier `deferred()`
capability did, which crashed at shutdown under Swoole coroutines because
Swoole does not carry observer state across coroutine switches unless
`swoole.enable_fiber_mock` is on; it was removed. Use `try`/`finally`.)

## Native AOP interception

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
issued (when another extension, e.g. a profiler, observes calls) with an
explicit `END`; and `zend_clear_exception()` rewinds the
current opline to the throw bookmark, so the caller's opline is snapshotted
and restored around the failure-phase driver call.

## Inline template evaluation

```php
winter_boot_exec_inline(string $code, array $vars = []): mixed
```

Evaluates framework `#{...}` template code (always shaped `return <expr>;`)
with `$vars` bound as variables — the native counterpart of the former
`eval()`-based expansion, so scanners no longer flag dynamic evaluation in
PHP code. The variables are installed into the caller frame's symbol table
(exactly where the old variable-variable injection put them, and the table
the executed code frame shares); they live and die with that frame, as
before. Integer keys, invalid names, and the two reserved names that
shadowed the old wrapper's own locals (`__c_o_d_e`, `__namedArgs`) are
ignored. Requires a userland caller scope, otherwise it throws `Error`.
Compile/runtime failures propagate unchanged; the framework wraps them in
`AopException` exactly like the old path.

Repeated evaluations skip recompilation through a small compilation cache
keyed by code, caller scope, and compile-time namespace (all three shape the
compiled op_array: the scope grants private-member access, and unqualified
names resolve against the namespace). Stored scopes are verified live against
the class table on every hit; code owning static vars is never cached, so no
state leaks across calls; the list is bounded (256 entries) and fails open to
compiling every time. Results are identical to compiling fresh — see
`tests/023-exec-inline-cache.phpt`.

## Single-call template substitution

```php
winter_boot_expand_template(string $template, array $pairs): string
```

Replaces each literal placeholder key with its already-evaluated value in
pair order — one native call for the whole key instead of one
`str_replace()` pass per placeholder from PHP. Semantics match
`str_replace($search, $replace, $template)` exactly: all occurrences per
pair, no rescan of inserted text within a pair (so a value containing a later
placeholder re-expands, as before), values coerced with the same conversion
`str_replace()` applies, converted upfront so a bad value throws before any
substitution. See `tests/022-expand-template.phpt`, which diffs every case
against the `str_replace()` oracle.

## Compatibility

- PHP **8.5+**.
- NTS and ZTS builds; OPcache compatible.
- Coexists with `ext-swoole`.

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
php -d extension=winter_boot.so -r 'echo winter_boot_expand_template("a-{x}", ["{x}" => "ok"]), "\n";'
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

Coverage: advice registration and validation, proceed/skip/exception paths
of intercepted calls, return-type gating, argument snapshots (including
variadics), inherited sibling beans, inline-code evaluation and its cache,
and template expansion against the `str_replace()` oracle.

Memory safety: Valgrind is not available in this environment (no root to
install it). Substitutes used: full suite plus 10k–30k-iteration mixed
(normal/throwing) stress loops under both Zend MM and system malloc
(`USE_ZEND_ALLOC=0`) — no heap errors, flat memory deltas — and OPcache CLI
runs.

## Benchmark

`benchmark/bench_expand.php` covers the template path: repeated
`winter_boot_exec_inline()` of one method-call-shaped code string runs ~7x
faster cached (~0.08 vs ~0.58 us/call — the compile disappears), while
`winter_boot_expand_template()` is at parity with `str_replace()` by design
(both are single C passes; the win is one call and one audited substitution
path, not speed).

## Files

- `winter_boot.c`, `php_winter_boot.h` — extension source
- `config.m4` — build configuration
- `tests/*.phpt` — PHPT test suite
- `run_phpt.php` — minimal PHPT runner (no php-src checkout needed)
- `benchmark/bench_expand.php` — template-path benchmark
