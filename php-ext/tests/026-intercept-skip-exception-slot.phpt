--TEST--
native interception leaves the return slot UNDEF when a skipped call throws
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static $mode = 'badValue';
        public static function begin($target, string $class, string $method, array $args): array {
            switch (self::$mode) {
                case 'beginThrows':
                    throw new \RuntimeException('begin threw');
                case 'protocol':
                    return ['proceed' => false];
                default:
                    return ['proceed' => false, 'value' => 123];
            }
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class SlotTarget {
        public function m(): string { return 'body'; }
    }
    winter_boot_advise(SlotTarget::class, 'm');

    // A temporary owned by $t shares the call's result slot; the caller's
    // exception handling must not free it a second time.
    function slotRun(SlotTarget $s, string $a): array {
        $t = $a . 'q';
        try { $r = $s->m(); } catch (Throwable $e) { }
        return [$t, $t];
    }
    foreach (['badValue', 'beginThrows', 'protocol'] as $mode) {
        NativeAopDriver::$mode = $mode;
        $s = new SlotTarget();
        $keep = [];
        for ($i = 0; $i < 2000; $i++) {
            $keep[] = slotRun($s, str_repeat('x', 40) . $i);
        }
        echo $mode, ': ', strlen(implode('', array_merge(...$keep))), "\n";
    }
}
?>
--EXPECT--
badValue: 177780
beginThrows: 177780
protocol: 177780
