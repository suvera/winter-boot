--TEST--
native interception: void/never/union skip rules and bean-family gate
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static $skipValue = null;
        public static int $begins = 0;
        public static function begin($target, string $class, string $method, array $args): array {
            self::$begins++;
            return ['proceed' => false, 'value' => self::$skipValue];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class BaseSvc {
        public function svc(): string { return "live"; }
    }
    class ChildSvc extends BaseSvc {}
    class OtherSvc extends BaseSvc {}
    function resetState(): void { NativeAopDriver::$begins = 0; NativeAopDriver::$skipValue = null; }
    // advised on the child: shared op_array, gate allows child family only
    winter_boot_advise(ChildSvc::class, 'svc');
    $c = new ChildSvc();
    resetState();
    NativeAopDriver::$skipValue = "hit";
    var_dump($c->svc());
    echo "begins=", NativeAopDriver::$begins, "\n";
    // parent-typed instance shares the op_array but is outside the family
    $o = new OtherSvc();
    resetState();
    var_dump($o->svc());
    echo "begins=", NativeAopDriver::$begins, "\n";
    // void accepts null skip
    class VoidSvc {
        public function run(): void { echo "LIVE\n"; }
        public function uni(): int|string { return 1; }
        public function noRet(): never { throw new \RuntimeException("x"); }
    }
    winter_boot_advise(VoidSvc::class, 'run');
    winter_boot_advise(VoidSvc::class, 'uni');
    winter_boot_advise(VoidSvc::class, 'noRet');
    $v = new VoidSvc();
    resetState();
    $v->run();
    echo "void-ok\n";
    NativeAopDriver::$skipValue = "s";
    var_dump($v->uni());
    NativeAopDriver::$skipValue = 1.5;
    try { $v->uni(); } catch (Throwable $e) { echo get_class($e), "\n"; }
    try { $v->noRet(); } catch (Throwable $e) { echo get_class($e), "\n"; }
}
?>
--EXPECT--
string(3) "hit"
begins=1
string(4) "live"
begins=0
void-ok
string(1) "s"
TypeError
TypeError
