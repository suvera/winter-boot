<?php

declare(strict_types=1);

namespace dev\winterframework\web\session;

use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use SessionHandlerInterface;

/**
 * Opens and commits per-request sessions without ext/session.
 *
 * The session id travels ONLY through HttpRequest::getCookie() (inbound)
 * and ResponseEntity::withCookie() (outbound). ResponseEntity is created
 * fresh per request, so emitting the cookie is race-free. The backing
 * store is driven directly via SessionHandlerInterface (open/read/write/
 * destroy/close); the native session starter is never called and no
 * superglobal or native session function is referenced anywhere in this
 * namespace.
 */
class SessionManager {
    /**
     * Session ids carry the same charset as PHP's default sid charset:
     * alphanumerics plus comma and hyphen, 1..128 chars.
     */
    private const ID_PATTERN = '/^[a-zA-Z0-9,-]{1,128}$/';

    public function open(
        HttpRequest $req,
        SessionHandlerInterface $store,
        SessionOptions $o
    ): RequestSession {
        $id = $req->getCookie($o->name);
        if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            return new RequestSession(self::mintId(), [], true);
        }

        $username = '';
        $sessionType = 0;
        $store->open('', $o->name);
        try {
            if ($store instanceof SessionIdentityStore) {
                try {
                    $row = $store->readWithIdentity($id);
                } catch (\Throwable) {
                    return new RequestSession($id, [], true);
                }
                $raw = $row['data'] ?? '';
                $username = (string)($row['username'] ?? '');
                $sessionType = (int)($row['sessionType'] ?? 0);
            } else {
                $raw = $store->read($id);
            }
        } finally {
            $store->close();
        }

        if (!is_string($raw) || $raw === '') {
            return new RequestSession($id, [], true);
        }

        $data = self::decode($raw);
        if (!is_array($data)) {
            return new RequestSession($id, [], true);
        }
        return new RequestSession($id, $data, false, $username, $sessionType);
    }

    public function commit(
        RequestSession $s,
        ResponseEntity $res,
        SessionHandlerInterface $store,
        SessionOptions $o
    ): void {
        if ($s->isDestroyed()) {
            $store->open('', $o->name);
            try {
                $store->destroy($s->getId());
            } finally {
                $store->close();
            }
            $res->withCookie($o->name, '', time() - 3600, $o->path, $o->domain, $o->secure, $o->httponly);
            return;
        }

        $store->open('', $o->name);
        try {
            if ($store instanceof SessionIdentityStore) {
                $store->writeWithIdentity(
                    $s->getId(),
                    serialize($s->getAll()),
                    $s->getUsername(),
                    $s->getSessionType()
                );
            } else {
                $store->write($s->getId(), serialize($s->getAll()));
            }
        } finally {
            $store->close();
        }
        $expires = $o->expirySecs > 0 ? time() + $o->expirySecs : 0;
        $res->withCookie($o->name, $s->getId(), $expires, $o->path, $o->domain, $o->secure, $o->httponly);
    }

    private static function mintId(): string {
        return bin2hex(random_bytes(16));
    }

    private static function decode(string $raw): mixed {
        // Corrupt store rows must degrade to an empty session, and
        // unserialize() raises E_NOTICE/E_WARNING on corrupt input, so
        // suppress strictly inside this call. Stored payloads originate
        // server-side from serialize() in commit(), never from the client.
        try {
            return @unserialize($raw);
        } catch (\Throwable) {
            return false;
        }
    }
}
