<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\pdbc\pdo\PdoConnection;
use winterBootTests\Support\TestCase;
use WeakReference;

class Fix214Test extends TestCase {

    // ---- closed connections really disconnect -------------------------

    /**
     * A PDOStatement held by an uncollected reference cycle kept the PDO
     * handle (and its database socket) alive after close(), so pools that
     * closed idle connections leaked open sessions until PHP's cycle
     * collector happened to run.
     */
    public function testCloseReleasesPdoHeldByReferenceCycle(): void {
        $conn = new PdoConnection('sqlite::memory:');
        $pdo = $conn->getPdo();
        $weak = WeakReference::create($pdo);

        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $cycle->stmt = $pdo->prepare('select 1');
        unset($cycle, $pdo);

        $this->assertTrue($weak->get() !== null, 'the cycle keeps the handle alive before close()');
        $conn->close(true);
        $this->assertNull($weak->get(), 'close() must release the PDO handle');
        $this->assertTrue($conn->isClosed());
    }

    public function testCloseTwiceIsHarmless(): void {
        $conn = new PdoConnection('sqlite::memory:');
        $conn->close();
        $conn->close();
        $this->assertTrue($conn->isClosed());
    }
}
