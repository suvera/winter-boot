--TEST--
native interception skip path releases arguments and restores the caller frame
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static $throw = false;
        public static function begin($target, string $class, string $method, array $args): array {
            if (self::$throw) {
                throw new \RuntimeException('begin threw');
            }
            return ['proceed' => false, 'value' => 'skipped'];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class Tok {
        public function __construct(public string $n) {}
        public function __destruct() { echo "  destruct {$this->n}\n"; }
    }
    class FrameTarget {
        public function fixed(Tok $t): string { return 'body'; }
        public function vari(Tok ...$t): string { return 'body'; }
        public function byRef(array &$a): string { $a[] = 'body'; return 'body'; }
        public static function stat(Tok $t): string { return 'body'; }
    }
    foreach (['fixed', 'vari', 'byRef', 'stat'] as $m) {
        winter_boot_advise(FrameTarget::class, $m);
    }
    $s = new FrameTarget();

    echo "fixed:\n"; $s->fixed(new Tok('fixed')); echo "  after\n";
    echo "extra positional:\n"; $s->fixed(new Tok('a'), new Tok('extra')); echo "  after\n";
    echo "variadic named:\n"; $s->vari(x: new Tok('named')); echo "  after\n";
    echo "static:\n"; FrameTarget::stat(new Tok('static')); echo "  after\n";
    echo "call_user_func:\n"; call_user_func([$s, 'fixed'], new Tok('cuf')); echo "  after\n";
    $arr = ['x'];
    $s->byRef($arr);
    echo "byRef untouched: ", count($arr), "\n";
    echo "begin threw:\n";
    NativeAopDriver::$throw = true;
    try { $s->fixed(new Tok('thrown')); } catch (RuntimeException $e) { echo "  caught ", $e->getMessage(), "\n"; }
    // The exception's trace holds the argument; dropping it releases the arg.
    unset($e);
    echo "  after\n";
    NativeAopDriver::$throw = false;

    // The caller's own exceptions must still reach its catch block.
    function frameCaller(FrameTarget $s): string {
        $s->fixed(new Tok('caller'));
        try {
            $z = 0;
            return (string) (1 % $z);
        } catch (DivisionByZeroError $e) {
            return 'caught';
        }
    }
    $r = frameCaller($s);
    echo "caller exception: ", $r, "\n";
    echo "done\n";
}
?>
--EXPECT--
fixed:
  destruct fixed
  after
extra positional:
  destruct a
  destruct extra
  after
variadic named:
  destruct named
  after
static:
  destruct static
  after
call_user_func:
  destruct cuf
  after
byRef untouched: 1
begin threw:
  caught begin threw
  destruct thrown
  after
  destruct caller
caller exception: caught
done
