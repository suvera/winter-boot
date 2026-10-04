--TEST--
deferred() scopes are isolated across nested calls
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function inner() {
    deferred(function () { echo "inner-defer\n"; });
    echo "inner-body\n";
}
function outer() {
    deferred(function () { echo "outer-defer\n"; });
    echo "outer-body\n";
    inner();
    echo "outer-after\n";
}
outer();
?>
--EXPECT--
outer-body
inner-body
inner-defer
outer-after
outer-defer
