--TEST--
deferred() works in methods and callbacks may register further defers
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
class Worker {
    public function run() {
        deferred(function () { echo "method-defer\n"; });
        echo "method-body\n";
    }
}
(new Worker())->run();
function nested() {
    deferred(function () {
        echo "outer-cb\n";
        deferred(function () { echo "nested-cb\n"; });
    });
    echo "body\n";
}
nested();
?>
--EXPECT--
method-body
method-defer
body
outer-cb
nested-cb
