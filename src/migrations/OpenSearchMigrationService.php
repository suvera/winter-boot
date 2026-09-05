<?php

declare(strict_types=1);

namespace dev\winterframework\migrations;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\OpenSearchMigrationException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\util\yaml\YamlParser;

/**
 * OpenSearchMigrationService
 *
 * Applies JSON based OpenSearch migrations (index templates, ISM policies and
 * index schemas) against every configured OpenSearch connection that has
 * migrations enabled:
 *
 * ```yaml
 * opensearch:
 *     -   name: opensearch
 *         hosts:
 *             - https://localhost:9200
 *         username: admin
 *         password: secret
 *         ssl_verification: false
 *         migrations:
 *             enabled: true
 * ```
 *
 * The connection list is read from the `opensearch` application property and,
 * additionally, from every `*.yml` / `*.yaml` file in the config directory
 * that carries a top-level `opensearch:` key (e.g. a module `configFile` such
 * as `opensearch-config.yml`, which the migration application never loads as
 * a module).
 *
 * Directory layout (mirrors SqlMigrationService):
 *
 * ```
 * /migrations/
 * └── opensearch/
 *     └── opensearch/              # one folder per connection name
 *         ├── sf-entities-template.json
 *         └── release-1.0/
 *             └── 002-retention-policy.json
 * ```
 *
 * A migration file is one JSON document. An explicit envelope
 * {method, path, body?, name?} is sent as a raw request (escape hatch).
 * Otherwise the filename decides first:
 *
 *  - `*-template.json` -> PUT /_index_template/{name}. A full composable template
 *    body (with `index_patterns`) is sent as-is; a raw `settings`/`mappings`/
 *    `aliases` definition is wrapped into one, defaulting `index_patterns` to
 *    ["{name}*"] unless the file states them explicitly.
 *  - `*-policy.json`   -> PUT /_plugins/_ism/policies/{name} (body as-is).
 *  - any other name    -> derived from content: `index_patterns` means index
 *    template, a top-level `policy` object means ISM policy, and
 *    `settings`/`mappings`/`aliases` means PUT /{name} (create index).
 *
 * The resource `{name}` defaults to the file name without `.json` and without
 * a trailing `-template`, `-policy` or `-index` suffix, so
 * `sf-entities-template.json` manages the `sf-entities` template.
 *
 * Idempotency: executed migrations are recorded as documents in the
 * `winter_migrations` index (one document per connection + relative path,
 * same idea as the `winter_migrations` SQL table), together with a SHA-256
 * hash of the file content. Re-runs skip files whose hash is unchanged and
 * re-apply files whose content changed (templates are updated in place via
 * upsert PUTs). Template PUTs are natural upserts; index
 * creation first checks whether the index already exists so re-running
 * against a cluster whose state index was lost still succeeds instead of
 * failing with `resource_already_exists_exception`. ISM policy PUTs 409 with
 * a version conflict when the policy already exists (e.g. created outside the
 * migrator with no state-index record); that case is likewise treated as
 * success and adopted into the state index. Note that re-applying an
 * index-schema file does not alter a live index: OpenSearch only applies
 * settings/mappings at index-creation time.
 */
class OpenSearchMigrationService {
    use Wlf4p;

    private const MIGRATIONS_INDEX = 'winter_migrations';

    /** @var array<int, array> */
    private array $osConfigs = [];
    private int $skippedCount = 0;

    public function __construct(
        private ApplicationContext $appCtx,
        private string $osBasePath,
        private string $configDir
    ) {
        $this->osBasePath = rtrim($osBasePath, '/');
    }

    public function executeMigrations(): void {
        $this->logInfo("Starting OpenSearch migrations execution from: {$this->osBasePath}");

        $this->loadOpenSearchConfigs();

        if (empty($this->osConfigs)) {
            $this->logInfo("No OpenSearch connections with migrations enabled found");
            return;
        }

        foreach ($this->osConfigs as $config) {
            $this->executeMigrationsForConnection($config);
        }

        if ($this->skippedCount > 0) {
            $this->logInfo("Skipped {$this->skippedCount} already executed migration(s)");
        }

        $this->logInfo("All OpenSearch migrations executed successfully");
    }

    /**
     * Collects OpenSearch connections with `migrations.enabled: true`.
     *
     * Reads the `opensearch` application property (covers connections declared
     * directly in application.yml) plus every yml file in the config directory
     * carrying a top-level `opensearch:` key (covers module config files such
     * as opensearch-config.yml). Entries support both nested
     * `migrations: {enabled: true}` and flattened `migrations.enabled: true`
     * shapes, exactly like SqlMigrationService.
     */
    private function loadOpenSearchConfigs(): void {
        /** @var array<string, array> $byName */
        $byName = [];

        try {
            $fromCtx = $this->appCtx->getProperty('opensearch', []);
        } catch (\Throwable) {
            $fromCtx = [];
        }
        if (is_array($fromCtx)) {
            foreach ($fromCtx as $os) {
                if (is_array($os) && isset($os['name'])) {
                    $byName[strval($os['name'])] = $os;
                }
            }
        }

        foreach ($this->scanConfigDirForOpenSearchConfigs() as $os) {
            $byName[strval($os['name'])] = $os;
        }

        foreach ($byName as $os) {
            $migrations = $os['migrations'] ?? [];
            if (!empty($migrations) && ($migrations['enabled'] ?? false)) {
                $this->osConfigs[] = $os;
                continue;
            }

            // Check for flattened key (YAML flatten mode)
            if ($os['migrations.enabled'] ?? false) {
                $this->osConfigs[] = $os;
            }
        }
    }

    /**
     * @return array<int, array> OpenSearch connection entries found in config dir yml files.
     */
    private function scanConfigDirForOpenSearchConfigs(): array {
        $found = [];

        if (!is_dir($this->configDir)) {
            return $found;
        }

        $files = array_merge(
            glob(rtrim($this->configDir, '/') . '/*.yml') ?: [],
            glob(rtrim($this->configDir, '/') . '/*.yaml') ?: []
        );

        foreach ($files as $file) {
            try {
                $data = YamlParser::parseFile($file, false);
            } catch (\Throwable $e) {
                $this->logWarning("Skipping unreadable config file {$file}: {$e->getMessage()}");
                continue;
            }

            if (!is_array($data) || !isset($data['opensearch']) || !is_array($data['opensearch'])) {
                continue;
            }

            foreach ($data['opensearch'] as $os) {
                if (is_array($os) && isset($os['name'])) {
                    $found[] = $os;
                }
            }
        }

        return $found;
    }

    private function executeMigrationsForConnection(array $config): void {
        $connName = strval($config['name']);
        $this->logInfo("Processing migrations for OpenSearch connection: {$connName}");

        $connPath = $this->osBasePath . '/' . $connName;

        if (!is_dir($connPath)) {
            $this->logWarning("OpenSearch folder not found for connection {$connName}: {$connPath}");
            return;
        }

        $jsonFiles = DirectoryScanner::scanForJsonFiles($connPath);

        if (empty($jsonFiles)) {
            $this->logInfo("No JSON files found in {$connPath}");
            return;
        }

        $client = new OpenSearchHttpClient($config);
        $this->ensureMigrationsIndex($client);

        foreach ($jsonFiles as $jsonFile) {
            $this->executeJsonFile($client, $connName, $jsonFile);
        }
    }

    private function executeJsonFile(OpenSearchHttpClient $client, string $connName, array $fileInfo): void {
        $relativePath = $fileInfo['relative'];
        $fullPath = $fileInfo['path'];
        $migrationId = $connName . '/' . $relativePath;

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new OpenSearchMigrationException("Failed to read migration file: {$fullPath}");
        }
        $contentHash = hash('sha256', $content);

        $recordedHash = $this->getRecordedContentHash($client, $migrationId);
        if ($recordedHash !== null && $recordedHash === $contentHash) {
            $this->skippedCount++;
            return;
        }

        if ($recordedHash === null) {
            $this->logInfo("Executing migration: {$relativePath}");
        } elseif ($recordedHash === false) {
            $this->logInfo(
                "Migration was recorded without a content hash, re-applying: {$relativePath}"
            );
        } else {
            $this->logInfo("Migration content changed, re-applying: {$relativePath}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OpenSearchMigrationException(
                "Invalid JSON in migration file {$fullPath}: " . json_last_error_msg()
            );
        }
        if (!is_array($data)) {
            throw new OpenSearchMigrationException(
                "Migration file must contain a JSON object: {$fullPath}"
            );
        }

        $operation = $this->resolveOperation($data, $fullPath, $content);
        $this->applyOperation($client, $operation);

        $this->recordMigration($client, $connName, $migrationId, $relativePath, $contentHash);
        $this->logInfo("Migration executed successfully: {$relativePath}");
    }

    /**
     * @return array{kind: string, method: string, path: string, body: mixed, name: string}
     */
    /**
     * Request bodies go out byte-for-byte wherever no rebuilding is needed, because
     * PHP assoc arrays cannot distinguish `{}` from `[]`: re-encoding a decoded body
     * would turn e.g. `"read_only": {}` into `"read_only": []`, which OpenSearch
     * rejects. Rebuilt bodies (template fold/wrap, envelopes) are re-encoded from an
     * object-preserving decode so empty objects survive.
     */
    private function resolveOperation(array $data, string $fullPath, string $rawContent): array {
        // Explicit envelope: {"method": "PUT", "path": "/...", "body": {...}, "name": "..."}
        if (isset($data['method']) && isset($data['path'])) {
            $method = strtoupper(strval($data['method']));
            $path = strval($data['path']);
            if ($method === '' || $path === '' || $path[0] !== '/') {
                throw new OpenSearchMigrationException(
                    "Invalid envelope in migration file {$fullPath}: "
                    . "'method' must be non-empty and 'path' must start with '/'"
                );
            }
            return [
                'kind' => 'raw',
                'method' => $method,
                'path' => $path,
                'body' => $this->encodeEnvelopeBody($fullPath, $rawContent),
                'name' => strval($data['name'] ?? basename($fullPath, '.json')),
            ];
        }

        $fileBase = basename($fullPath, '.json');

        // Filename intent wins: *-template.json files are index templates, even when
        // the body is a raw settings/mappings definition without index_patterns.
        if (preg_match('/-template$/i', $fileBase)) {
            return $this->resolveIndexTemplateOperation($data, $fileBase, $fullPath, $rawContent);
        }

        if (preg_match('/-policy$/i', $fileBase)) {
            $name = $this->deriveResourceName($fileBase, false);
            return [
                'kind' => 'ism-policy',
                'method' => 'PUT',
                'path' => '/_plugins/_ism/policies/' . rawurlencode($name),
                'body' => $rawContent,
                'name' => $name,
            ];
        }

        if (array_key_exists('index_patterns', $data)) {
            return $this->resolveIndexTemplateOperation($data, $fileBase, $fullPath, $rawContent);
        }

        if (array_key_exists('policy', $data) && is_array($data['policy'])) {
            $name = $this->deriveResourceName($fileBase, false);
            return [
                'kind' => 'ism-policy',
                'method' => 'PUT',
                'path' => '/_plugins/_ism/policies/' . rawurlencode($name),
                'body' => $rawContent,
                'name' => $name,
            ];
        }

        if (array_key_exists('settings', $data)
            || array_key_exists('mappings', $data)
            || array_key_exists('aliases', $data)
        ) {
            // OpenSearch index names must be lowercase.
            $name = $this->deriveResourceName($fileBase, true);
            return [
                'kind' => 'index',
                'method' => 'PUT',
                'path' => '/' . rawurlencode($name),
                'body' => $rawContent,
                'name' => $name,
            ];
        }

        throw new OpenSearchMigrationException(
            "Cannot determine migration type for file {$fullPath}: expected a document with "
            . "'index_patterns' (index template), a top-level 'policy' object (ISM policy), "
            . "'settings'/'mappings'/'aliases' (index schema), "
            . "or an explicit envelope {'method', 'path', 'body'}"
        );
    }

    /**
     * @return array{kind: string, method: string, path: string, body: mixed, name: string}
     */
    private function resolveIndexTemplateOperation(
        array $data,
        string $fileBase,
        string $fullPath,
        string $rawContent
    ): array {
        $name = $this->deriveResourceName($fileBase, false);

        // Rebuild from an object-preserving decode: assoc arrays cannot tell {} from [],
        // so folding/wrapping from $data would corrupt empty objects into empty lists.
        $obj = json_decode($rawContent);
        if (!($obj instanceof \stdClass)) {
            throw new OpenSearchMigrationException(
                "Invalid migration file {$fullPath}: expected a JSON object document"
            );
        }

        if (property_exists($obj, 'template') && !($obj->template instanceof \stdClass)) {
            throw new OpenSearchMigrationException(
                "Invalid migration file {$fullPath}: 'template' must be an object"
            );
        }

        $explicitPatterns = property_exists($obj, 'index_patterns') ? $obj->index_patterns : null;
        if ($explicitPatterns !== null && (!is_array($explicitPatterns) || empty($explicitPatterns))) {
            throw new OpenSearchMigrationException(
                "Invalid migration file {$fullPath}: 'index_patterns' must be a non-empty list"
            );
        }

        $templateSection = ($obj->template ?? null) instanceof \stdClass
            ? $obj->template
            : new \stdClass();
        $moved = false;
        foreach (['settings', 'mappings', 'aliases'] as $key) {
            if (property_exists($obj, $key)) {
                $templateSection->$key = $obj->$key;
                $moved = true;
            }
        }

        // Pristine full-form template: nothing to fold or default, send byte-for-byte.
        if ($explicitPatterns !== null && !$moved && !empty((array)$templateSection)) {
            return [
                'kind' => 'index-template',
                'method' => 'PUT',
                'path' => '/_index_template/' . rawurlencode($name),
                'body' => $rawContent,
                'name' => $name,
            ];
        }

        if ($moved && $explicitPatterns !== null) {
            $this->logInfo(
                "Template file {$fullPath} declares 'index_patterns' with a raw definition, "
                . "folding settings/mappings under the 'template' section"
            );
        }

        // Raw definition without index_patterns: OpenSearch rejects a template without
        // them, so default to "<name>*" (covers both the bare index and dated/rolled-over
        // variants such as "sf-events-2026.09.04"). Put an explicit "index_patterns"
        // list in the file to override this default.
        $patterns = $explicitPatterns ?? [$name . '*'];
        if ($explicitPatterns === null) {
            $this->logInfo(
                "Template file {$fullPath} has no 'index_patterns', defaulting to "
                . json_encode($patterns)
            );
        }

        if (empty((array)$templateSection)) {
            throw new OpenSearchMigrationException(
                "Cannot determine migration type for template file {$fullPath}: expected a document with "
                . "'index_patterns', 'settings'/'mappings'/'aliases', or a 'template' object"
            );
        }

        $body = ['index_patterns' => $patterns];
        foreach (get_object_vars($obj) as $key => $value) {
            if ($key === 'index_patterns'
                || $key === 'template'
                || in_array($key, ['settings', 'mappings', 'aliases'], true)
            ) {
                continue;
            }
            $body[$key] = $value;
        }
        $body['template'] = $templateSection;

        $encoded = json_encode($body);
        if ($encoded === false) {
            throw new OpenSearchMigrationException(
                "Failed to JSON-encode template body for migration file {$fullPath}"
            );
        }

        return [
            'kind' => 'index-template',
            'method' => 'PUT',
            'path' => '/_index_template/' . rawurlencode($name),
            'body' => $encoded,
            'name' => $name,
        ];
    }

    private function encodeEnvelopeBody(string $fullPath, string $rawContent): ?string {
        $obj = json_decode($rawContent);
        if (!($obj instanceof \stdClass) || !property_exists($obj, 'body')) {
            return null;
        }
        $encoded = json_encode($obj->body);
        if ($encoded === false) {
            throw new OpenSearchMigrationException(
                "Failed to JSON-encode envelope body in migration file {$fullPath}"
            );
        }
        return $encoded;
    }

    private function deriveResourceName(string $fileBase, bool $lowercase): string {
        $name = trim($fileBase);
        $name = preg_replace('/-(template|policy|index)$/i', '', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            throw new OpenSearchMigrationException(
                "Cannot derive resource name from file base '{$fileBase}'"
            );
        }

        return $lowercase ? strtolower($name) : $name;
    }

    private function applyOperation(OpenSearchHttpClient $client, array $operation): void {
        $kind = $operation['kind'];

        if ($kind === 'index' && $this->indexExists($client, $operation['name'])) {
            $this->logInfo(
                "Index '{$operation['name']}' already exists, skipping creation "
                . "(recorded as executed)"
            );
            return;
        }

        $this->logInfo(
            "Applying {$kind} '{$operation['name']}': {$operation['method']} {$operation['path']}"
        );

        $response = $client->request($operation['method'], $operation['path'], $operation['body']);
        $status = $response['status'];

        if ($status >= 200 && $status < 300) {
            return;
        }

        // Lost state index + already created index racing a re-run: treat as success.
        if ($kind === 'index'
            && $status === 400
            && $this->responseErrorType($response['body']) === 'resource_already_exists_exception'
        ) {
            $this->logInfo(
                "Index '{$operation['name']}' already exists (reported by OpenSearch), "
                . "treating as success"
            );
            return;
        }

        // Policy created outside the migrator (no state-index record) racing a
        // re-run: ISM PUT without seq_no/if_primary_term 409s with a
        // version-conflict reason instead of upserting. Adopt it as success so
        // recordMigration() runs and future runs skip by content hash.
        // (Index-template PUTs are natural upserts and do not 409, so they stay strict.)
        if ($kind === 'ism-policy'
            && $status === 409
            && $this->isVersionConflict($response['body'])
        ) {
            $this->logInfo(
                "ISM policy '{$operation['name']}' already exists (reported by OpenSearch), "
                . "treating as success"
            );
            return;
        }

        throw new OpenSearchMigrationException(
            "OpenSearch migration failed for {$kind} '{$operation['name']}': "
            . "{$operation['method']} {$operation['path']} returned status {$status}: "
            . $this->responseErrorReason($response['body'])
        );
    }

    private function indexExists(OpenSearchHttpClient $client, string $name): bool {
        $response = $client->request('GET', '/' . rawurlencode($name));

        if ($response['status'] === 200) {
            return true;
        }
        if ($response['status'] === 404) {
            return false;
        }

        throw new OpenSearchMigrationException(
            "Failed to check existence of index '{$name}': status {$response['status']}: "
            . $this->responseErrorReason($response['body'])
        );
    }

    private function ensureMigrationsIndex(OpenSearchHttpClient $client): void {
        $response = $client->request('GET', '/' . self::MIGRATIONS_INDEX);

        if ($response['status'] === 200) {
            return;
        }
        if ($response['status'] !== 404) {
            throw new OpenSearchMigrationException(
                'Failed to check migrations index ' . self::MIGRATIONS_INDEX
                . ": status {$response['status']}: " . $this->responseErrorReason($response['body'])
            );
        }

        $this->logInfo("Creating migrations index: " . self::MIGRATIONS_INDEX);
        $created = $client->request('PUT', '/' . self::MIGRATIONS_INDEX, [
            'settings' => ['index' => ['number_of_shards' => 1, 'number_of_replicas' => 0]],
        ]);

        if (($created['status'] < 200 || $created['status'] >= 300)
            && !($created['status'] === 400
                && $this->responseErrorType($created['body']) === 'resource_already_exists_exception')
        ) {
            throw new OpenSearchMigrationException(
                'Failed to create migrations index ' . self::MIGRATIONS_INDEX
                . ": status {$created['status']}: " . $this->responseErrorReason($created['body'])
            );
        }
    }

    /**
     * Returns the recorded content hash for a migration, or null when the migration
     * was never executed. Returns false when a state document exists but predates
     * hash tracking (treated as changed, so it is re-applied once and re-recorded
     * with a hash).
     *
     * @return string|false|null
     */
    private function getRecordedContentHash(
        OpenSearchHttpClient $client,
        string $migrationId
    ): string|false|null {
        $response = $client->request(
            'GET',
            '/' . self::MIGRATIONS_INDEX . '/_doc/' . rawurlencode($migrationId)
        );

        if ($response['status'] === 200) {
            if (($response['body']['found'] ?? false) !== true) {
                return null;
            }
            $hash = $response['body']['_source']['content_hash'] ?? null;
            return is_string($hash) && $hash !== '' ? $hash : false;
        }
        if ($response['status'] === 404) {
            return null;
        }

        throw new OpenSearchMigrationException(
            "Failed to check migration state for '{$migrationId}': status {$response['status']}: "
            . $this->responseErrorReason($response['body'])
        );
    }

    private function recordMigration(
        OpenSearchHttpClient $client,
        string $connName,
        string $migrationId,
        string $relativePath,
        string $contentHash
    ): void {
        $response = $client->request(
            'PUT',
            '/' . self::MIGRATIONS_INDEX . '/_doc/' . rawurlencode($migrationId),
            [
                'migration_path' => $relativePath,
                'connection' => $connName,
                'content_hash' => $contentHash,
                'executed_at' => date('c'),
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new OpenSearchMigrationException(
                "Failed to record migration '{$migrationId}': status {$response['status']}: "
                . $this->responseErrorReason($response['body'])
            );
        }
    }

    /**
     * True when an error body reports a version conflict (pre-existing document).
     *
     * ISM's 409 body carries the conflict as a plain-string `error`
     * ("[sf-entities]: version conflict, document already exists ..."), for
     * which responseErrorType()/responseErrorReason() both return that string;
     * other shapes use `error.type = version_conflict_engine_exception` with
     * the detail in `error.reason`. Matching both spellings ("version conflict"
     * and "version_conflict") across type and reason covers either form without
     * weakening other 409s.
     */
    private function isVersionConflict(mixed $body): bool {
        foreach ([$this->responseErrorType($body), $this->responseErrorReason($body)] as $text) {
            if (stripos($text, 'version conflict') !== false
                || stripos($text, 'version_conflict') !== false
            ) {
                return true;
            }
        }
        return false;
    }

    private function responseErrorType(mixed $body): string {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && isset($error['type'])) {
                return strval($error['type']);
            }
            if (is_string($error)) {
                return $error;
            }
        }
        return '';
    }

    private function responseErrorReason(mixed $body): string {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && isset($error['reason'])) {
                return strval($error['reason']);
            }
            if (is_string($error)) {
                return $error;
            }
            $encoded = json_encode($body);
            return $encoded === false ? 'unknown error' : $encoded;
        }
        if (is_string($body) && $body !== '') {
            return $body;
        }
        return 'unknown error';
    }
}
