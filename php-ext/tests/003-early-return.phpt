--TEST--
deferred() runs on every early return path
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function pick($n) {
    deferred(function () { echo "cleanup\n"; });
    if ($n < 0) {
        return "negative";
    }
    if ($n === 0) {
        return "zero";
    }
    return "positive";
}
echo pick(-1), "\n";
echo pick(0), "\n";
echo pick(5), "\n";
?>
--EXPECT--
cleanup
negative
cleanup
zero
cleanup
positive
