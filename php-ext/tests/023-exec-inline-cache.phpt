--TEST--
winter_boot_exec_inline() compilation cache preserves scope and freshness
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
class ScopeA {
    private string $secret = 'A!';
    public function run(object $g) {
        return winter_boot_exec_inline('return $g->secret;', ['g' => $g]);
    }
}
class ScopeB {
    public function run(object $g) {
        return winter_boot_exec_inline('return $g->secret;', ['g' => $g]);
    }
}

$a = new ScopeA();
// First call compiles; second call must hit the cache with the same scope.
var_dump((new ScopeA())->run($a));
var_dump((new ScopeA())->run($a));
// Same code string under a different scope is a different entry: private
// access from ScopeB still fails instead of reusing ScopeA's op_array.
try {
    (new ScopeB())->run($a);
    echo "NO-THROW\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
// Cached entry still serves its own scope afterwards.
var_dump((new ScopeA())->run($a));

// Bound variables are per-call: the cache never freezes values.
$rp = new ReflectionProperty(ScopeA::class, 'secret');
$a2 = new ScopeA();
$rp->setValue($a2, 'A2!');
var_dump((new ScopeA())->run($a2));

// Static-bearing code is never cached: fresh state on every call, exactly
// like compiling every time (compare with the eval() oracle).
$code = 'static $c = 0; return ++$c;';
var_dump(winter_boot_exec_inline($code, []));
var_dump(winter_boot_exec_inline($code, []));
var_dump(winter_boot_exec_inline($code, []));
$legacy = function () use ($code) { return eval($code); };
var_dump($legacy());
var_dump($legacy());

// Failed compilations are not cached either: still ParseError every time.
foreach (['native', 'native'] as $side) {
    try {
        winter_boot_exec_inline('return $;', []);
        echo "NO-THROW\n";
    } catch (ParseError $e) {
        echo "ParseError\n";
    }
}
?>
--EXPECT--
string(2) "A!"
string(2) "A!"
Error: Cannot access private property ScopeA::$secret
string(2) "A!"
string(3) "A2!"
int(1)
int(1)
int(1)
int(1)
int(1)
ParseError
ParseError
