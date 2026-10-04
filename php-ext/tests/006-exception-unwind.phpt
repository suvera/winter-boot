--TEST--
deferred() runs during exception unwinding and preserves the original exception
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function fails() {
    deferred(function () { echo "cleanup-a\n"; });
    deferred(function () { echo "cleanup-b\n"; });
    throw new RuntimeException("boom");
}
try {
    fails();
} catch (Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
    var_dump($e->getPrevious());
}
?>
--EXPECT--
cleanup-b
cleanup-a
caught: boom
NULL
