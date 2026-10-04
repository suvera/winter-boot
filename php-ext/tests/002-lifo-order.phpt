--TEST--
deferred() executes multiple callbacks in LIFO order
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function lifo() {
    deferred(function () { echo "first\n"; });
    deferred(function () { echo "second\n"; });
    deferred(function () { echo "third\n"; });
    echo "body\n";
}
lifo();
?>
--EXPECT--
body
third
second
first
