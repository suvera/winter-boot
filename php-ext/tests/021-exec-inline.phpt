--TEST--
winter_boot_exec_inline() evaluates template code with bound variables
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
// Oracle: the PHP-level eval() expansion this function replaces.
function legacyEval(string $code, array $vars) {
    foreach ($vars as $name => $value) {
        if ($name == '__c_o_d_e' || $name == '__namedArgs') {
            continue;
        }
        $$name = $value;
    }
    return eval($code);
}

class Greeter {
    public function __construct(private string $id) {}
    public function getId(): string { return $this->id; }
    public function compute(string $suffix): string { return $this->id . $suffix; }
}

$order = new Greeter('ord-9');
$cases = [
    ['return $id;', ['id' => 5], 5],
    ['return $name;', ['name' => 'bob'], 'bob'],
    ['return $order->getId();', ['order' => $order], 'ord-9'],
    ['return $order->compute($suffix);', ['order' => $order, 'suffix' => '!'], 'ord-9!'],
    ['return $missing;', [], null],
    ['return [$a, $b];', ['a' => 1, 'b' => 2], [1, 2]],
];

foreach ($cases as $i => [$code, $vars, $expected]) {
    $native = @winter_boot_exec_inline($code, $vars);
    $legacy = @legacyEval($code, $vars);
    var_dump($native === $legacy && $native === $expected);
}

// Reserved names never shadow: skipped, so the code sees nothing.
var_dump(winter_boot_exec_inline('return $x ?? "dflt";', ['x' => 1, '__c_o_d_e' => 'return 999;', '__namedArgs' => []]));
// Integer and invalid keys are ignored.
var_dump(winter_boot_exec_inline('return "ok";', [0 => 'junk', '9lives' => 'junk', 'fine' => 1]));
// Original vars array is untouched (no moved references).
$vars = ['id' => 5];
winter_boot_exec_inline('return $id;', $vars);
var_dump($vars);
// Syntax errors propagate as ParseError.
try {
    winter_boot_exec_inline('return $;', []);
    echo "NO-THROW\n";
} catch (ParseError $e) {
    echo "ParseError\n";
}
// Runtime throws propagate unchanged.
try {
    winter_boot_exec_inline('throw new RuntimeException("inner");', []);
    echo "NO-THROW\n";
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
string(2) "ok"
array(1) {
  ["id"]=>
  int(5)
}
ParseError
inner
