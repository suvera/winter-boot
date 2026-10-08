--TEST--
winter_boot_expand_template() caps geometric expansion and fails closed
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
// Chained pairs where each token expands to many copies of the next token
// grow the result as fanout^depth from a tiny input. Before the cap this
// drove a single call into an OOM fatal; now it throws Error, fail closed.
function amplify(int $fanout, int $depth): array {
    $tok = static fn (int $i): string => '{{' . $i . '}}';
    $pairs = [];
    for ($k = 0; $k < $depth; $k++) {
        $pairs[$tok($k)] = str_repeat($tok($k + 1), $fanout);
    }
    $pairs[$tok($depth)] = 'A';
    return [$tok(0), $pairs];
}

// Would expand to ~10^9 bytes: must throw, not allocate.
[$tpl, $pairs] = amplify(10, 9);
try {
    winter_boot_expand_template($tpl, $pairs);
    echo "NO-THROW\n";
} catch (Error $e) {
    echo get_class($e), "\n";
    var_dump(strpos($e->getMessage(), 'expansion exceeds') !== false);
}

// A single pass that alone crosses the 16 MiB cap also fails closed.
try {
    winter_boot_expand_template('#{x}', ['#{x}' => str_repeat('y', 17 * 1024 * 1024)]);
    echo "NO-THROW\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}

// Legitimate traffic is untouched: ordinary substitution, re-expansion,
// and a large-but-under-cap result all still succeed.
var_dump(winter_boot_expand_template('hi-#{n}', ['#{n}' => 'bob']));
var_dump(winter_boot_expand_template('#{a}', ['#{a}' => '#{b}', '#{b}' => 'X']));
$big = winter_boot_expand_template('#{x}', ['#{x}' => str_repeat('z', 1024 * 1024)]);
var_dump(strlen($big));

// A pass that does not grow the string is allowed even when the subject
// already exceeds the cap: the cap bounds amplification, not large literals.
$oversized = str_repeat('a', 17 * 1024 * 1024) . '#{x}';
$same = winter_boot_expand_template($oversized, ['#{x}' => '#{y}']); // 4 -> 4 bytes
var_dump(strlen($same) === strlen($oversized));
$shrunk = winter_boot_expand_template($oversized, ['#{x}' => '']);   // 4 -> 0 bytes
var_dump(strlen($shrunk) === strlen($oversized) - 4);
?>
--EXPECT--
Error
bool(true)
Error
string(6) "hi-bob"
string(1) "X"
int(1048576)
bool(true)
bool(true)
