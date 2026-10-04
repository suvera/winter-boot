--TEST--
deferred() closures observe by-value captures, by-reference captures and object references
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function captures() {
    $x = 1;
    $obj = new stdClass();
    $obj->n = 0;
    deferred(function () use ($x, $obj) { echo "byval x=$x obj={$obj->n}\n"; });
    deferred(function () use (&$x) { echo "byref x=$x\n"; });
    $x = 99;
    $obj->n = 7;
}
captures();
?>
--EXPECT--
byref x=99
byval x=1 obj=7
