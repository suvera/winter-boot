--TEST--
defered() is an alias of deferred() sharing the same LIFO stack
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function mixed() {
    deferred(function () { echo "via-deferred\n"; });
    defered(function () { echo "via-defered\n"; });
    echo "body\n";
}
mixed();
function aliasUnwind() {
    defered(function () { echo "alias-cleanup\n"; });
    throw new RuntimeException("boom");
}
try {
    aliasUnwind();
} catch (Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
body
via-defered
via-deferred
alias-cleanup
caught: boom
