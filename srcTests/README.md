# srcTests — Unit Tests

Zero-dependency test suite (no PHPUnit needed). Plain PHP 8.1+ with `php` on PATH.

## Run

From the repository root:

```sh
php srcTests/run.php
```

Exit code is `0` when all tests pass, `1` otherwise.

## Layout

- `run.php` — discovers `*Test.php` in this directory and runs every public
  `test*` method, printing `ok`/`FAIL` per test plus a pass/fail summary.
- `Support/TestCase.php` — base class with `assertSame`, `assertTrue`,
  `assertFalse`, `assertNull`, `assertThrows` (throws `AssertionFailed` on failure).
- `Support/StubPropertySource.php` — in-memory `PropertySource` for tests. Pick
  the dataset via the `dataset` key of the `propertySources` entry in the
  fixture yml; register rows in `StubPropertySource::$datasets` before building
  the context.
- `fixtures/<name>/application.yml` — config dir passed to
  `new WinterPropertyContext(['.../fixtures/<name>'])`.
- `PropertyExpressionTest.php` — tests for the `||` fallback-chain DSL in
  `WinterPropertyContext::parseValue`.

## Add a test

1. Create `SomethingTest.php` in this directory, namespace `winterBootTests`,
   class `SomethingTest extends \winterBootTests\Support\TestCase`.
2. Add public methods starting with `test`. No registration needed — `run.php`
   picks the file up automatically.
