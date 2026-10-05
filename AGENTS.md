# AGENTS.md — Winter Boot Framework

Instructions for coding agents working in this repo. Framework usage reference lives in
`.agents/skills/winter-boot/SKILL.md` — load it before framework work (DI, REST, DB,
caching, async, modules).

## Layout

- `src/` — framework source (`dev\winterframework\` PSR-4).
- `srcTests/` — zero-dependency tests (`winterBootTests\` namespace, extend `Support\TestCase`).
- `srcTests/swoole/` — Swoole-runtime tests (needs the `swoole` extension).
- `build/docker/` — runtime base image; `build/sqlmigrator/` — migration PHAR (`box.json`).
- User docs live in a separate repo: `/home/nrama/GitHub/suvera.github.io/wb-mdx`
  (content under `src/content/docs/`, agent rules in its `AGENTS.md`).

## Build & Test

- `php srcTests/run.php` from repo root — full unit suite, must be green before finishing.
- `php srcTests/swoole/run.php` — Swoole suite (each method isolated in a subprocess;
  a post-result shutdown segfault is a known environmental issue, not a test failure).
- `php -l <file>` on every edited PHP file.

## Working Conventions

- Backward compatibility first: fixes must not change legitimate-traffic behavior.
  Fail closed on attacker-controlled input (strip/sanitize, never echo raw).
- Every behavior change ships with its regression test in `srcTests/` (follow the
  `*Test.php` + `test*` method convention so `run.php` picks it up). Prove the test
  fails before the fix, passes after.
- Never log secrets, raw bodies, headers, or query values (see `DispatcherServlet`
  conventions: `sanitizeUriForError()`, no-raw-body logging).
- Never commit, push, or tag unless explicitly asked. Leave work uncommitted for review.
- Per-request state belongs to the coroutine scope, never to a singleton field or a
  static: key it by `CoroutineScopeProvider::getScopeId()` and drop it in `defer()`
  (see `AbstractPlatformTransactionManager::txnStack()`).
- Pools fail closed: a coroutine never falls back to a shared/process connection, and
  a connection is reset (`ResettableConnection::resetForReuse()`) before it is reused.
  PDO/OCI pooling lives once in `pdbc/support/ScopedConnectionPool`.
- Data read back from stores is decoded with `SerializationUtil::unserialize()`, never
  a bare `unserialize()`.
- Caches, labels and lookup tables keyed by request data need a size cap (see
  `WinterRequestMappingRegistry::MAX_CACHED_PATHS`); metric labels use route templates.
- Keep pure logic in small static helpers (e.g. `DispatcherServlet::stripContextPath()`)
  so tests can cover it without booting the server.
- Proving "fails before the fix": run the new tests against a clean checkout of `HEAD`
  with a **copied** `vendor/` (a symlinked `vendor/` autoloads the working tree's `src/`).
- `CHANGELOG.md`: one line per change, grouped under Fixed / Security / Added / Changed.

## Docs Sync (mandatory)

Any noticeable framework change — new `application.yml` keys, new
attributes/annotations, behavior changes, new modules — must also update the user
documentation in `/home/nrama/GitHub/suvera.github.io/wb-mdx` in the respective file
under `src/content/docs/` (concept pages for behavior, plus
`reference/application-yml.mdx` for every new or changed config key). Verify with
`./build.sh` in `wb-mdx` (shippable compressed output; `--pretty` is inspect-only).
Never land a user-visible framework change with docs missing.

## Release Checklist (before tagging `X.Y.Z`)

1. Bump `VERSION.txt` at the repo root to `X.Y.Z` (exact bytes, no trailing newline).
   The startup banner reads this file via `WinterApplicationRunner::getBootVersion()` —
   NOT the git tag. A stale file prints a stale version.
2. Confirm `VERSION.txt` ships everywhere: packed in `build/sqlmigrator/box.json`
   (`files` list), mirrored via `COPY VERSION.txt /VERSION.txt` in
   `build/docker/Dockerfile`, and included in Composer dists (no `.gitattributes`
   exclusion).
3. Docs synced per above; `CHANGELOG.md` entry added.
