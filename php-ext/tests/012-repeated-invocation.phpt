--TEST--
deferred() survives repeated invocations without leaking memory
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
function workload($i) {
    deferred(function () {});
    deferred(function () use ($i) {});
    return $i;
}
for ($i = 0; $i < 1000; $i++) {
    workload($i);
}
$before = memory_get_usage();
for ($i = 0; $i < 20000; $i++) {
    workload($i);
}
$after = memory_get_usage();
$delta = $after - $before;
echo $delta < 65536 ? "OK\n" : "LEAK delta=$delta\n";
?>
--EXPECT--
OK
