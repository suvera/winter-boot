--TEST--
winter_boot_advise() registers methods and rejects unsound targets
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
class AdvTarget {
    public function work(): string { return "work"; }
    public function __construct() {}
    public function __destruct() {}
}
abstract class AdvAbs { abstract public function am(); }
var_dump(winter_boot_is_advised(AdvTarget::class, 'work'));
winter_boot_advise(AdvTarget::class, 'work');
var_dump(winter_boot_is_advised(AdvTarget::class, 'work'));
var_dump(winter_boot_is_advised(AdvTarget::class, 'missing'));
foreach ([
    [AdvTarget::class, 'missing'],
    [AdvAbs::class, 'am'],
    [AdvTarget::class, '__construct'],
    [AdvTarget::class, '__destruct'],
] as [$c, $m]) {
    try {
        winter_boot_advise($c, $m);
        echo "NO-THROW $m\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}
winter_boot_advise(AdvTarget::class, 'work');
var_dump(winter_boot_is_advised(AdvTarget::class, 'work'));
?>
--EXPECT--
bool(false)
bool(true)
bool(false)
Error
Error
Error
Error
bool(true)
