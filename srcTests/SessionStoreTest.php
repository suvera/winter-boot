<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\session\PdbcSessionStore;
use dev\winterframework\web\session\RequestSession;
use dev\winterframework\web\session\SessionManager;
use dev\winterframework\web\session\SessionOptions;

/** @param array<string,string> $jar */
class JarCookieRequest extends HttpRequest {
    public function __construct(private array $jar = []) {
    }

    public function getCookie(string $name): ?string {
        return $this->jar[$name] ?? null;
    }
}

class FakePdbcTemplate implements PdbcTemplate {
    /** @var array<string,array{session_data:string,username:string,expiry:int,created_at:int,updated_at:int,session_type:int}> */
    public array $rows = [];
    public bool $failInsertOnce = false;

    public function queryForMap(string $sql, array|BindVars $bindVars = []): array {
        $id = is_array($bindVars) ? $bindVars[0] : null;
        if (is_string($id) && isset($this->rows[$id])) {
            return $this->rows[$id];
        }
        throw new \RuntimeException('empty record');
    }

    public function update(
        string $sql,
        array|BindVars $bindVars,
        array|OutBindVars $outBindVars = [],
        array &$generatedKeys = []
    ): int {
        $binds = is_array($bindVars) ? array_values($bindVars) : [];
        if (str_contains($sql, 'CASE')) {
            // Portability rule: no conditional SQL. MySQL, SQLite,
            // PostgreSQL and Oracle must all run the exact statements
            // below, so any branching belongs in PHP, not in SQL.
            throw new \RuntimeException('conditional SQL is not portable');
        }
        if (str_starts_with($sql, 'UPDATE')) {
            if (count($binds) === 6) {
                [$payload, $username, $expiry, $now, $type, $id] = $binds;
            } else {
                // Username omitted: the stored name must survive.
                [$payload, $expiry, $now, $type, $id] = $binds;
                $username = null;
            }
            if (!isset($this->rows[$id])) {
                return 0;
            }
            $this->rows[$id] = [
                'session_data' => $payload,
                'username' => $username ?? $this->rows[$id]['username'],
                'expiry' => $expiry,
                'created_at' => $this->rows[$id]['created_at'] ?? 0,
                'updated_at' => $now,
                'session_type' => $type,
            ];
            return 1;
        }
        if (str_starts_with($sql, 'INSERT')) {
            [$id, $username, $expiry, $createdAt, $updatedAt, $type, $payload] = $binds;
            if ($this->failInsertOnce) {
                $this->failInsertOnce = false;
                // Simulate the concurrent winner: the row now exists.
                $this->rows[$id] = [
                    'session_data' => base64_encode('stale'),
                    'username' => 'racer',
                    'expiry' => 0,
                    'created_at' => 1000,
                    'updated_at' => 1000,
                    'session_type' => 1,
                ];
                throw new \RuntimeException('duplicate key (simulated race)');
            }
            if (isset($this->rows[$id])) {
                throw new \RuntimeException('duplicate key');
            }
            $this->rows[$id] = [
                'session_data' => $payload,
                'username' => $username,
                'expiry' => $expiry,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'session_type' => $type,
            ];
            return 1;
        }
        if (str_starts_with($sql, 'DELETE')) {
            if (count($binds) === 1 && str_contains($sql, 'id = ?')) {
                $gone = isset($this->rows[$binds[0]]) ? 1 : 0;
                unset($this->rows[$binds[0]]);
                return $gone;
            }
            $now = $binds[0];
            $swept = 0;
            foreach ($this->rows as $id => $row) {
                if ($row['expiry'] > 0 && $row['expiry'] < $now) {
                    unset($this->rows[$id]);
                    $swept++;
                }
            }
            return $swept;
        }
        throw new \RuntimeException('unexpected SQL: ' . $sql);
    }

    public function batchUpdate(string $sql, array $arrayBindVars): array {
        throw new \RuntimeException('not needed');
    }

    public function execute(string $sql, array|BindVars $bindVars = [], ?PreparedStatementCallback $action = null): mixed {
        throw new \RuntimeException('not needed');
    }

    public function query(
        string $sql,
        array|BindVars $bindVars,
        callable|ResultSetExtractor|RowCallbackHandler|RowMapper $processor
    ): mixed {
        throw new \RuntimeException('not needed');
    }

    public function queryForList(string $sql, array|BindVars $bindVars = []): array {
        throw new \RuntimeException('not needed');
    }

    public function queryForScalar(string $sql, array|BindVars $bindVars = []): int|string|float|bool|null {
        throw new \RuntimeException('not needed');
    }

    public function queryForObject(
        string $sql,
        array|BindVars $bindVars,
        string|RowMapper|null $classOrMapper = null
    ): object {
        throw new \RuntimeException('not needed');
    }

    public function queryForObjects(string $sql, array|BindVars $bindVars, string $ppaClass): array {
        throw new \RuntimeException('not needed');
    }

    public function updateObjects(object ...$ppaObjects): void {
    }

    public function deleteObjects(object ...$ppaObjects): void {
    }
}

class SessionStoreTest extends \winterBootTests\Support\TestCase {
    public function testPdbcRoundTrip(): void {
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->set('user', 'cara');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $resumed = $mgr->open(new JarCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $this->assertSame('cara', $resumed->get('user'));
        $this->assertFalse($resumed->isNew());
    }

    public function testPdbcInsertRaceRetriesUpdate(): void {
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);
        $db->failInsertOnce = true;

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->set('user', 'dan');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        // The retry UPDATE must overwrite the concurrent winner's row.
        $resumed = $mgr->open(new JarCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $this->assertSame('dan', $resumed->get('user'));
    }

    public function testPdbcExpiredRowIsMissAndGcSweeps(): void {
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db, 'winter_sessions', 3600);
        $db->rows['old'] = [
            'session_data' => base64_encode('x'),
            'username' => 'zed',
            'expiry' => time() - 10,
            'created_at' => 1000,
            'updated_at' => 1000,
            'session_type' => 0,
        ];
        $db->rows['fresh'] = [
            'session_data' => base64_encode('y'),
            'username' => 'zed',
            'expiry' => time() + 3600,
            'created_at' => 1000,
            'updated_at' => 1000,
            'session_type' => 0,
        ];

        $this->assertSame('', $store->read('old'));
        $this->assertTrue($store->read('fresh') !== '');

        $swept = $store->gc(0);
        $this->assertSame(1, $swept);
        $this->assertFalse(isset($db->rows['old']));
        $this->assertTrue(isset($db->rows['fresh']));
    }

    public function testPdbcAuditTimestamps(): void {
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        // Insert path stamps both columns.
        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->set('user', 'erin');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);
        $row = $db->rows[$sess->getId()];
        $this->assertTrue($row['created_at'] > 0, 'created_at must be stamped');
        $this->assertSame($row['created_at'], $row['updated_at']);

        // Update path preserves created_at and refreshes updated_at.
        $db->rows[$sess->getId()] = [
            'session_data' => base64_encode(serialize(['user' => 'erin'])),
            'username' => 'erin',
            'expiry' => 0,
            'created_at' => 1000,
            'updated_at' => 1000,
            'session_type' => 2,
        ];
        $resumed = $mgr->open(new JarCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $resumed->set('user', 'erin2');
        $mgr->commit($resumed, new ResponseEntity(), $store, $opts);
        $row2 = $db->rows[$sess->getId()];
        $this->assertSame(1000, $row2['created_at']);
        $this->assertTrue($row2['updated_at'] >= time() - 5, 'updated_at must refresh on write');
    }

    public function testPdbcIdentityRoundTrip(): void {
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->setUsername('frank');
        $sess->setSessionType(2);
        $sess->set('role', 'admin');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $row = $db->rows[$sess->getId()];
        $this->assertSame('frank', $row['username']);
        $this->assertSame(2, $row['session_type']);

        $resumed = $mgr->open(new JarCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $this->assertFalse($resumed->isNew());
        $this->assertSame('frank', $resumed->getUsername());
        $this->assertSame(2, $resumed->getSessionType());
        $this->assertSame('admin', $resumed->get('role'));
    }

    public function testPdbcResumePreservesIdentityOnRecommit(): void {
        // Resuming hydrates identity onto the bag, so a later commit
        // that never touches username/type must not wipe the row.
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->setUsername('gail');
        $sess->setSessionType(1);
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $resumed = $mgr->open(new JarCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $resumed->set('last_page', '/home');
        $mgr->commit($resumed, new ResponseEntity(), $store, $opts);

        $row = $db->rows[$sess->getId()];
        $this->assertSame('gail', $row['username']);
        $this->assertSame(1, $row['session_type']);
    }

    public function testPdbcEmptyUsernameKeepsStoredName(): void {
        // username is written once at login: a later save carrying no
        // name must not wipe the stored one.
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->setUsername('ivan');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $nameless = new RequestSession($sess->getId(), ['k' => 'v'], false, '', 0);
        $mgr->commit($nameless, new ResponseEntity(), $store, $opts);

        $this->assertSame('ivan', $db->rows[$sess->getId()]['username']);
    }

    public function testPdbcExplicitUsernameChangeWins(): void {
        $mgr = new SessionManager();
        $opts = new SessionOptions(name: 'SID');
        $db = new FakePdbcTemplate();
        $store = new PdbcSessionStore($db);

        $sess = $mgr->open(new JarCookieRequest([]), $store, $opts);
        $sess->setUsername('ivan');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $renamed = new RequestSession($sess->getId(), ['k' => 'v'], false, 'judy', 0);
        $mgr->commit($renamed, new ResponseEntity(), $store, $opts);

        $this->assertSame('judy', $db->rows[$sess->getId()]['username']);
    }

    public function testPdbcInvalidTableNameThrows(): void {
        $this->assertThrows(
            \InvalidArgumentException::class,
            function (): void {
                new PdbcSessionStore(new FakePdbcTemplate(), 'nope; DROP TABLE x');
            }
        );
    }

    public function testSessionOptionDefaults(): void {
        $opts = new SessionOptions();
        $this->assertSame('WINTERSESSID', $opts->name);
        $this->assertSame(0, $opts->expirySecs);
        $this->assertSame('/', $opts->path);
        $this->assertSame('', $opts->domain);
        $this->assertFalse($opts->secure);
        $this->assertTrue($opts->httponly);
    }
}
