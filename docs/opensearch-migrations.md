# OpenSearch Migrations

This feature applies JSON based OpenSearch migrations (index templates, ISM
policies and index schemas) against configured OpenSearch connections, with
execution tracking similar to [SQL migrations](sql-migrations.md).

## Quick Start

### 1. Enable Migrations in Config

Add `migrations.enabled: true` to your OpenSearch connection. This works both
for connections declared directly in `application.yml` and for connections in
a module config file such as `opensearch-config.yml` (the migration
application reads that file directly, the OpenSearch module itself is not
booted):

```yaml
opensearch:
    -   name: opensearch
        hosts:
            - https://localhost:9200
        username: admin
        password: secret
        ssl_verification: false
        migrations:
            enabled: true
```

### 2. Create Migration Directory Structure

```bash
mkdir -p /migrations/opensearch
```

Migrations live in one folder per connection name (`/migrations/opensearch/`
for a connection named `opensearch`).

### 3. Add JSON Files

Create `/migrations/opensearch/sf-entities-template.json`:

```json
{
  "settings": {
    "index": {
      "number_of_shards": 1,
      "number_of_replicas": 0
    }
  },
  "mappings": {
    "properties": {
      "entity_name": { "type": "keyword" }
    }
  }
}
```

### 4. Run Migrations

```bash
# Using PHAR (built with build/sqlmigrator/build.sh)
./winter-migrations-app.phar -c /path/to/config --sqlPath /path/to/migrations -m opensearch

# Using PHP CLI directly
php bin/migrate.php -c /path/to/config --sqlPath /path/to/migrations -m opensearch
```

Omit `-m` (or pass `-m sql`) to run SQL migrations instead. The `--sqlPath`
argument is the shared migrations root for both types.

## Migration File Types

An explicit envelope (`{method, path, body?, name?}`) is always sent as a raw
request. Otherwise the **filename decides first**:

| Filename | Action |
|---|---|
| `*-template.json` | `PUT /_index_template/{name}` |
| `*-policy.json` | `PUT /_plugins/_ism/policies/{name}` |
| anything else | derived from content (see below) |

For `*-template.json`, a full composable template body (with
`index_patterns`) is sent as-is. A raw `settings` / `mappings` / `aliases`
definition is wrapped into a valid template, defaulting `index_patterns` to
`["{name}*"]` (covers the bare index plus dated variants like
`sf-events-2026.09.04`); put an explicit `index_patterns` list in the file to
override that default.

For other filenames the content decides:

| Content | Action |
|---|---|
| document with `index_patterns` | `PUT /_index_template/{name}` |
| document with top-level `policy` object | `PUT /_plugins/_ism/policies/{name}` |
| document with `settings` / `mappings` / `aliases` | `PUT /{name}` (create index) |

The resource `{name}` defaults to the file name without `.json` and without a
trailing `-template`, `-policy` or `-index` suffix, so
`sf-entities-template.json` manages the `sf-entities` index (or template, or
policy, depending on content). Index names are lowercased because OpenSearch
requires lowercase index names.

Files are executed in alphabetical order (including sub-folders, e.g.
`release-1.0/`), and the relative path is the migration identity.

## Idempotency

Executed migrations are recorded as documents in the `winter_migrations`
index (one document per connection + relative path, the OpenSearch equivalent
of the `winter_migrations` SQL table), together with a SHA-256 hash of the
file content. Re-runs skip files whose hash is unchanged and re-apply files
whose content changed — so editing a template or policy file and re-running
updates it in place.

Additionally, each operation is safe to replay:

- index template / ISM policy PUTs are natural upserts;
- index creation first checks whether the index already exists, so re-running
  against a cluster whose `winter_migrations` index was lost succeeds instead
  of failing with `resource_already_exists_exception`.

Note that re-applying an index-schema file does not alter a live index:
OpenSearch applies settings/mappings at index-creation time. To change a live
index, add a new migration file with the appropriate API call (e.g. an
envelope `{method: PUT, path: /<index>/_mapping, ...}` to add a field).

## Notes

- The HTTP client is self-contained (no `opensearch-php` dependency): it uses
  the Swoole coroutine HTTP client inside a Swoole coroutine (same approach as
  the winter-opensearch `SwooleHttpHandler`), cURL when available, and the PHP
  stream wrapper as a fallback. Hosts are tried in order on transport failure.
- Supported connection keys: `hosts` (required), `username` / `password`
  (basic auth), `ssl_verification` (default `true`), `timeout` and
  `connect_timeout` in seconds, and `proxy` (explicit proxy URL, or `false`
  to disable proxying).
- First failure stops all migrations (no automatic rollback), same as SQL
  migrations.
