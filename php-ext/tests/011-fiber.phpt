--TEST--
deferred() works inside fibers and fires once when the fiber function exits
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
$fiber = new Fiber(function () {
    deferred(function () { echo "fiber-defer\n"; });
    echo "fiber-body\n";
    Fiber::suspend("paused");
    echo "fiber-resumed\n";
});
echo $fiber->start(), "\n";
$fiber->resume();
echo "---\n";
$throws = new Fiber(function () {
    deferred(function () { echo "fiber-cleanup\n"; });
    throw new RuntimeException("fiber-boom");
});
try {
    $throws->start();
} catch (Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
fiber-body
paused
fiber-resumed
fiber-defer
---
fiber-cleanup
caught: fiber-boom
