--TEST--
deferred() scopes are isolated across recursion
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function rec($n) {
    deferred(function () use ($n) { echo "defer $n\n"; });
    if ($n <= 0) {
        return;
    }
    rec($n - 1);
}
rec(2);
?>
--EXPECT--
defer 0
defer 1
defer 2
