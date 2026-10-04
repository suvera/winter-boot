--TEST--
body exception plus deferred exception are both preserved via the previous chain
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function bothFail() {
    deferred(function () { echo "cleanup\n"; throw new RuntimeException("cleanup-fail"); });
    throw new RuntimeException("original");
}
try {
    bothFail();
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
cleanup
caught: cleanup-fail
prev: original
