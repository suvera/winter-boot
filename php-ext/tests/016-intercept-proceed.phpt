--TEST--
native interception runs the driver chain around the body (proceed path)
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static array $calls = [];
        public static function begin($target, string $class, string $method, array $args): array {
            self::$calls[] = "begin:$method:" . json_encode($args);
            return ['proceed' => true, 'value' => null, 'exCtx' => new \stdClass(), 'interceptor' => new \stdClass()];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            self::$calls[] = "finish:$outcome:" . json_encode($payload);
            return null;
        }
    }
}
namespace {
    class ChainTarget {
        public int $runs = 0;
        public function compute(int $x): int {
            $this->runs++;
            return $x * 2;
        }
        public function plain(): string { return "plain"; }
    }
    winter_boot_advise(ChainTarget::class, 'compute');
    $t = new ChainTarget();
    var_dump($t->compute(21));
    var_dump($t->runs);
    var_dump($t->plain());
    print_r(\dev\winterframework\core\aop\NativeAopDriver::$calls);
}
?>
--EXPECT--
int(42)
int(1)
string(5) "plain"
Array
(
    [0] => begin:compute:[21]
    [1] => finish:returned:42
)
