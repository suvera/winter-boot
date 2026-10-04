--TEST--
deferred() runs on normal function return
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function greet($name) {
    deferred(function () { echo "cleanup\n"; });
    echo "hello $name\n";
    return "done";
}
var_dump(greet("world"));
?>
--EXPECT--
hello world
cleanup
string(4) "done"
