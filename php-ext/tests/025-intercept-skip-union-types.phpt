--TEST--
native interception skip path verifies union, nullable, DNF and pseudo return types
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
namespace dev\winterframework\core\aop {
    class NativeAopDriver {
        public static $skipValue = null;
        public static function begin($target, string $class, string $method, array $args): array {
            return ['proceed' => false, 'value' => self::$skipValue];
        }
        public static function finish($exCtx, $interceptor, string $outcome, $payload) {
            return null;
        }
    }
}
namespace {
    use dev\winterframework\core\aop\NativeAopDriver;
    class RE {} class Foo {} class Bar {} class C {}
    interface I1 {} interface I2 {} class Both implements I1, I2 {} class OnlyI1 implements I1 {}
    class Base {}
    class T extends Base {
        public function builtinOrClass(): array|RE { return []; }
        public function builtinOrList(): int|Foo|Bar { return 1; }
        public function dnf(): (I1&I2)|C { return new C(); }
        public function nullableClass(): ?Foo { return null; }
        public function stringOrFalse(): string|false { return false; }
        public function cb(): callable { return 'strlen'; }
        public function lateStatic(): static { return $this; }
        public function iter(): iterable { return []; }
        public function widen(): float { return 1.0; }
        public static function make(): static { return new static(); }
    }
    class TChild extends T {}
    foreach (['builtinOrClass', 'builtinOrList', 'dnf', 'nullableClass', 'stringOrFalse',
              'cb', 'lateStatic', 'iter', 'widen', 'make'] as $m) {
        winter_boot_advise(T::class, $m);
    }
    winter_boot_advise(TChild::class, 'make');
    $t = new T();
    $cases = [
        ['builtinOrClass', new RE()], ['builtinOrClass', [1]], ['builtinOrClass', 'x'],
        ['builtinOrList', 5], ['builtinOrList', new Bar()], ['builtinOrList', 'x'],
        ['dnf', new Both()], ['dnf', new C()], ['dnf', new OnlyI1()], ['dnf', 'x'], ['dnf', new Foo()],
        ['nullableClass', new Foo()], ['nullableClass', null], ['nullableClass', new Bar()],
        ['stringOrFalse', false], ['stringOrFalse', 'x'], ['stringOrFalse', true],
        ['cb', 'strlen'], ['cb', fn() => 1], ['cb', 5],
        ['lateStatic', $t], ['lateStatic', new Base()],
        ['iter', [1]], ['iter', new ArrayIterator([])], ['iter', 'x'],
        ['widen', 3], ['widen', '3'],
    ];
    foreach ($cases as [$m, $v]) {
        NativeAopDriver::$skipValue = $v;
        try {
            $t->$m();
            $r = 'ok';
        } catch (TypeError $e) {
            $r = 'TypeError';
        }
        echo $m, ' ', get_debug_type($v), ': ', $r, "\n";
    }
    // static return type resolves against the called class, not the declaring one.
    NativeAopDriver::$skipValue = new T();
    try { TChild::make(); echo "TChild::make T: ok\n"; } catch (TypeError $e) { echo "TChild::make T: TypeError\n"; }
    NativeAopDriver::$skipValue = new TChild();
    try { TChild::make(); echo "TChild::make TChild: ok\n"; } catch (TypeError $e) { echo "TChild::make TChild: TypeError\n"; }
}
?>
--EXPECT--
builtinOrClass RE: ok
builtinOrClass array: ok
builtinOrClass string: TypeError
builtinOrList int: ok
builtinOrList Bar: ok
builtinOrList string: TypeError
dnf Both: ok
dnf C: ok
dnf OnlyI1: TypeError
dnf string: TypeError
dnf Foo: TypeError
nullableClass Foo: ok
nullableClass null: ok
nullableClass Bar: TypeError
stringOrFalse bool: ok
stringOrFalse string: ok
stringOrFalse bool: TypeError
cb string: ok
cb Closure: ok
cb int: TypeError
lateStatic T: ok
lateStatic Base: TypeError
iter array: ok
iter ArrayIterator: ok
iter string: TypeError
widen int: ok
widen string: TypeError
TChild::make T: TypeError
TChild::make TChild: ok
