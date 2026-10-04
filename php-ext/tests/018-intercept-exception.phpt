--TEST--
native interception propagates body exceptions through finish and preserves them
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static array $calls = [];
        public static function begin($target, string $class, string $method, array $args): array {
            self::$calls[] = "begin:$method";
            return ['proceed' => true, 'value' => null, 'exCtx' => new \stdClass(), 'interceptor' => new \stdClass()];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            self::$calls[] = "finish:$outcome:" . ($payload instanceof \Throwable ? $payload->getMessage() : json_encode($payload));
            return null;
        }
    }
}
namespace {
    class FailTarget {
        public function boom(): string { throw new \RuntimeException("body-boom"); }
        public function rec(int $n): int {
            if ($n <= 0) { return 0; }
            return 1 + $this->rec($n - 1);
        }
    }
    winter_boot_advise(FailTarget::class, 'boom');
    winter_boot_advise(FailTarget::class, 'rec');
    $t = new FailTarget();
    try {
        $t->boom();
    } catch (Throwable $e) {
        echo "caught: ", $e->getMessage(), " prev=", var_export($e->getPrevious(), true), "\n";
    }
    var_dump($t->rec(3));
    print_r(\dev\winterframework\core\aop\NativeAopDriver::$calls);
}
?>
--EXPECT--
caught: body-boom prev=NULL
int(3)
Array
(
    [0] => begin:boom
    [1] => finish:threw:body-boom
    [2] => begin:rec
    [3] => begin:rec
    [4] => begin:rec
    [5] => begin:rec
    [6] => finish:returned:0
    [7] => finish:returned:1
    [8] => finish:returned:2
    [9] => finish:returned:3
)
