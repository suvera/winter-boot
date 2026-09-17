<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\session\RequestSession;
use dev\winterframework\web\session\SessionManager;
use dev\winterframework\web\session\SessionOptions;

/**
 * Cookieless stub: carries request cookies in an object property so the
 * test never touches $_COOKIE (superglobals are process-global and unsafe
 * under Swoole coroutines).
 */
class StubCookieRequest extends HttpRequest {
    /** @param array<string,string> $stubCookies */
    public function __construct(private array $stubCookies = []) {
    }

    public function getCookie(string $name): ?string {
        return $this->stubCookies[$name] ?? null;
    }
}

class MemorySessionStore implements \SessionHandlerInterface {
    /** @var array<string,string> */
    public array $rows = [];

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        return $this->rows[$id] ?? '';
    }

    public function write(string $id, string $data): bool {
        $this->rows[$id] = $data;
        return true;
    }

    public function destroy(string $id): bool {
        unset($this->rows[$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return 0;
    }
}

class RequestSessionTest extends \winterBootTests\Support\TestCase {
    private function opts(): SessionOptions {
        return new SessionOptions(name: 'SID');
    }

    private function cookieFor(ResponseEntity $res, string $name): ?object {
        foreach ($res->getCookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }
        return null;
    }

    public function testIsolationInterleave(): void {
        // NOTE: this test MUST fail on any $_SESSION-based design: native
        // ext/session state is process-global, so two interleaved requests
        // would share/overwrite one $_SESSION array. Per-request
        // RequestSession objects share nothing by construction.
        $mgr = new SessionManager();
        $store = new MemorySessionStore();
        $opts = $this->opts();

        $sessA = $mgr->open(new StubCookieRequest([]), $store, $opts);
        $sessB = $mgr->open(new StubCookieRequest([]), $store, $opts);
        $this->assertTrue($sessA->getId() !== $sessB->getId(), 'ids must differ');

        $sessA->set('user', 'alice');

        // Simulated interleave: B commits before A.
        $resB = new ResponseEntity();
        $mgr->commit($sessB, $resB, $store, $opts);
        $resA = new ResponseEntity();
        $mgr->commit($sessA, $resA, $store, $opts);

        $rowA = unserialize($store->rows[$sessA->getId()]);
        $this->assertSame('alice', $rowA['user']);

        $rowB = unserialize($store->rows[$sessB->getId()]);
        $this->assertFalse(isset($rowB['user']), 'B must not see A data');

        $cookieA = $this->cookieFor($resA, 'SID');
        $cookieB = $this->cookieFor($resB, 'SID');
        $this->assertSame($sessA->getId(), $cookieA->value);
        $this->assertSame($sessB->getId(), $cookieB->value);
    }

    public function testResumeRoundTrip(): void {
        $mgr = new SessionManager();
        $store = new MemorySessionStore();
        $opts = $this->opts();

        $sess = $mgr->open(new StubCookieRequest([]), $store, $opts);
        $sess->set('user', 'alice');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);

        $resumed = $mgr->open(new StubCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $this->assertSame($sess->getId(), $resumed->getId());
        $this->assertSame('alice', $resumed->get('user'));
        $this->assertFalse($resumed->isNew());
    }

    public function testDestroy(): void {
        $mgr = new SessionManager();
        $store = new MemorySessionStore();
        $opts = $this->opts();

        $sess = $mgr->open(new StubCookieRequest([]), $store, $opts);
        $sess->set('user', 'alice');
        $mgr->commit($sess, new ResponseEntity(), $store, $opts);
        $this->assertTrue(isset($store->rows[$sess->getId()]));

        $resumed = $mgr->open(new StubCookieRequest(['SID' => $sess->getId()]), $store, $opts);
        $resumed->destroy();
        $res = new ResponseEntity();
        $mgr->commit($resumed, $res, $store, $opts);

        $this->assertFalse(isset($store->rows[$sess->getId()]), 'store row must be deleted');
        $cookie = $this->cookieFor($res, 'SID');
        $this->assertTrue($cookie !== null, 'expiring cookie must be emitted');
        $this->assertTrue($cookie->expires <= time(), 'cookie must already be expired');
    }

    public function testInvalidIdMintsFresh(): void {
        $mgr = new SessionManager();
        $store = new MemorySessionStore();
        $opts = $this->opts();

        $sess = $mgr->open(new StubCookieRequest(['SID' => '!!! not a valid id !!!']), $store, $opts);
        $this->assertTrue($sess->isNew());
        $this->assertTrue((bool)preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $sess->getId()));
        $this->assertSame(null, $sess->get('user'));
    }

    public function testMultipleSessionTypesStayApart(): void {
        // Two login flows, two cookie setups, one shared store: each
        // flow sees only its own sessions.
        $mgr = new SessionManager();
        $store = new MemorySessionStore();
        $adminOpts = new SessionOptions(name: 'ADMINSESSID', expirySecs: 900);
        $userOpts = new SessionOptions(name: 'USERSESSID', expirySecs: 86400);

        $admin = $mgr->open(new StubCookieRequest([]), $store, $adminOpts);
        $admin->setUsername('root');
        $resAdmin = new ResponseEntity();
        $mgr->commit($admin, $resAdmin, $store, $adminOpts);

        // The admin cookie means nothing to the user flow.
        $asUser = $mgr->open(
            new StubCookieRequest(['ADMINSESSID' => $admin->getId()]),
            $store,
            $userOpts
        );
        $this->assertTrue($asUser->isNew());

        $user = $mgr->open(new StubCookieRequest([]), $store, $userOpts);
        $user->setUsername('alice');
        $resUser = new ResponseEntity();
        $mgr->commit($user, $resUser, $store, $userOpts);

        $this->assertSame(2, count($store->rows));
        $adminCookie = null;
        foreach ($resAdmin->getCookies() as $cookie) {
            if ($cookie->name === 'ADMINSESSID') {
                $adminCookie = $cookie;
            }
        }
        $this->assertSame($admin->getId(), $adminCookie->value);
        $userCookie = null;
        foreach ($resUser->getCookies() as $cookie) {
            if ($cookie->name === 'USERSESSID') {
                $userCookie = $cookie;
            }
        }
        $this->assertSame($user->getId(), $userCookie->value);
    }

    public function testSessionBagBasics(): void {
        $sess = new RequestSession('abc123');
        $this->assertSame('abc123', $sess->getId());
        $this->assertTrue($sess->isNew());
        $this->assertSame('', $sess->getUsername());
        $this->assertSame(0, $sess->getSessionType());
        $this->assertSame('dflt', $sess->get('missing', 'dflt'));
        $sess->set('k', 'v');
        $this->assertSame('v', $sess->get('k'));
        $sess->remove('k');
        $this->assertSame(null, $sess->get('k'));
        $sess->setUsername('hank');
        $sess->setSessionType(3);
        $this->assertSame('hank', $sess->getUsername());
        $this->assertSame(3, $sess->getSessionType());
    }
}
