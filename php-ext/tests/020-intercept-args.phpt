--TEST--
native interception: argument fidelity, static calls and parent-call delta
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static array $seen = [];
        public static function begin($target, string $class, string $method, array $args): array {
            self::$seen[] = [$class, $method, $args, $target === null ? 'null-target' : get_class($target)];
            return ['proceed' => true, 'value' => null, 'exCtx' => new \stdClass(), 'interceptor' => new \stdClass()];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class ParityLog { public static $body = null; }
    class ArgSvc {
        public function vari(string ...$parts): string { return implode(',', $parts); }
        public function mixed(int $a, string ...$rest): string { return "$a:" . implode(',', $rest); }
        public function named(int $a, int $b = 5): int { return $a + $b; }
        public function byref(int &$x): void { $x *= 2; }
        public static function stat(int $x): int { return $x + 1; }
        public function parity(int &$x, $y): void {
            ParityLog::$body = func_get_args();
            $x = 999;
        }
    }
    class SubSvc extends ArgSvc {
        public function callParent(): string { return parent::vari('p'); }
    }
    foreach (['vari', 'mixed', 'named', 'byref', 'stat', 'parity'] as $m) {
        winter_boot_advise(ArgSvc::class, $m);
    }
    winter_boot_advise(SubSvc::class, 'callParent');
    $t = new ArgSvc();
    var_dump($t->vari('a', 'b'));
    var_dump($t->mixed(1, 'x', 'y'));
    var_dump($t->named(b: 10, a: 1));
    $x = 21;
    $t->byref($x);
    var_dump($x);
    var_dump(ArgSvc::stat(41));
    $y = 7;
    $t->parity($y, 'z');
    var_dump($y);
    $driver = end(NativeAopDriver::$seen);
    echo json_encode(ParityLog::$body) === json_encode($driver[2]) ? "MATCH\n" : "MISMATCH\n";
    $s = new SubSvc();
    var_dump($s->callParent());
    foreach (NativeAopDriver::$seen as [$c, $m, $a, $tgt]) {
        echo "$m ", json_encode($a), " $tgt\n";
    }
}
?>
--EXPECT--
string(3) "a,b"
string(5) "1:x,y"
int(11)
int(42)
int(42)
int(999)
MATCH
string(1) "p"
vari ["a","b"] ArgSvc
mixed [1,"x","y"] ArgSvc
named [1,10] ArgSvc
byref [21] ArgSvc
stat [41] null-target
parity [7,"z"] ArgSvc
callParent [] SubSvc
vari ["p"] SubSvc
