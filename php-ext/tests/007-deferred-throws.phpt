--TEST--
a throwing deferred callback does not skip remaining callbacks
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function cleanupFails() {
    deferred(function () { echo "first\n"; throw new RuntimeException("E1"); });
    deferred(function () { echo "second\n"; throw new RuntimeException("E2"); });
    echo "body\n";
}
try {
    cleanupFails();
} catch (Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
    $prev = $e->getPrevious();
    while ($prev !== null) {
        echo "prev: ", $prev->getMessage(), "\n";
        $prev = $prev->getPrevious();
    }
}
?>
--EXPECT--
body
second
first
caught: E1
prev: E2
