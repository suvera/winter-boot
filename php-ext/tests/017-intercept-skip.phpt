--TEST--
native interception skip path short-circuits the body with a verified value
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static $skipValue = 'CACHED';
        public static function begin($target, string $class, string $method, array $args): array {
            return ['proceed' => false, 'value' => self::$skipValue];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            echo "FINISH-CALLED\n";
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class SkipTarget {
        public int $runs = 0;
        public function load(): string { $this->runs++; return "live"; }
        public function num(): int { return 1; }
    }
    winter_boot_advise(SkipTarget::class, 'load');
    winter_boot_advise(SkipTarget::class, 'num');
    $t = new SkipTarget();
    var_dump($t->load());
    var_dump($t->runs);
    NativeAopDriver::$skipValue = 7;
    var_dump($t->num());
    NativeAopDriver::$skipValue = "nope";
    try {
        $t->num();
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
    var_dump($t->runs);
}
?>
--EXPECT--
string(6) "CACHED"
int(0)
int(7)
TypeError
int(0)
