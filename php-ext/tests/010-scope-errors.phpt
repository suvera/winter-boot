--TEST--
deferred() fails closed outside supported scopes
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
try {
    deferred(function () {});
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    deferred(42);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
function gen() {
    deferred(function () {});
    yield 1;
}
try {
    foreach (gen() as $v) {
    }
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
Error
TypeError
Error
