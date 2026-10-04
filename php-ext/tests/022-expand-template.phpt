--TEST--
winter_boot_expand_template() matches str_replace() substitution semantics
--SKIPIF--
<?php if (!extension_loaded("winter_boot")) die("skip winter_boot not loaded"); ?>
--FILE--
<?php
// Oracle: the str_replace() tail this function replaces in buildNameByContext().
function legacyExpand(string $tpl, array $pairs) {
    return str_replace(array_keys($pairs), array_values($pairs), $tpl);
}

class Money {
    public function __construct(private int $cents) {}
    public function __toString(): string { return '$' . ($this->cents / 100); }
}
class Opaque {}

$cases = [
    // template, pairs
    ['hi-#{name}-#{n}', ['#{name}' => 'bob', '#{n}' => 3]],
    ['k-#{g}-#{g}', ['#{g}' => new Money(199)]],
    ['v-#{n}', ['#{n}' => null]],
    ['b-#{t}-#{f}', ['#{t}' => true, '#{f}' => false]],
    ['f-#{x}', ['#{x}' => 3.5]],
    ['plain-template', ['#{a}' => 'X']],
    ['', ['#{a}' => 'X']],
    ['#{a}', []],
    ['#{a}#{b}#{a}', ['#{a}' => '1', '#{b}' => '22']],
    ['del-#{x}-end', ['#{x}' => '']],
    ['uni-héllo-#{w}-wörld', ['#{w}' => 'WÖRld']],
    ['#{a}', [0 => 'intkey']],
    ['n-5', [5 => 'five']],
    // Sequential re-expansion: replacement of #{a} contains #{b}, which the
    // later pair expands — str_replace() rescans, so must we.
    ['#{a}', ['#{a}' => '#{b}', '#{b}' => 'X']],
    // Reverse order does NOT re-expand (#{b} pass already ran).
    ['#{a}', ['#{b}' => 'X', '#{a}' => '#{b}']],
    // Same placeholder text twice in template.
    ['#{x}+#{x}', ['#{x}' => 7]],
];

foreach ($cases as $i => [$tpl, $pairs]) {
    $native = winter_boot_expand_template($tpl, $pairs);
    $legacy = legacyExpand($tpl, $pairs);
    var_dump($native === $legacy);
    var_dump($native);
}

// Binary-safe: NUL bytes pass through untouched on both sides.
$native = winter_boot_expand_template("bin-\x00-#{x}", ['#{x}' => 'y']);
$legacy = legacyExpand("bin-\x00-#{x}", ['#{x}' => 'y']);
var_dump($native === $legacy);
var_dump(bin2hex($native));

// Array value: warning + "Array" on both sides (suppressed here).
$native = @winter_boot_expand_template('#{a}', ['#{a}' => [1, 2]]);
$legacy = @legacyExpand('#{a}', ['#{a}' => [1, 2]]);
var_dump($native === $legacy);
var_dump($native);

// Unconvertible object: Error on both sides, template untouched.
foreach (['native', 'legacy'] as $side) {
    try {
        if ($side === 'native') {
            winter_boot_expand_template('#{a}-tail', ['#{a}' => new Opaque()]);
        } else {
            legacyExpand('#{a}-tail', ['#{a}' => new Opaque()]);
        }
        echo "NO-THROW\n";
    } catch (Error $e) {
        echo get_class($e), "\n";
    }
}

// References coerce by value on both sides.
$ref = 'R';
$refPairs = ['#{a}' => &$ref];
var_dump(winter_boot_expand_template('[#{a}]', $refPairs) === legacyExpand('[#{a}]', $refPairs));
?>
--EXPECT--
bool(true)
string(8) "hi-bob-3"
bool(true)
string(13) "k-$1.99-$1.99"
bool(true)
string(2) "v-"
bool(true)
string(4) "b-1-"
bool(true)
string(5) "f-3.5"
bool(true)
string(14) "plain-template"
bool(true)
string(0) ""
bool(true)
string(4) "#{a}"
bool(true)
string(4) "1221"
bool(true)
string(8) "del--end"
bool(true)
string(24) "uni-héllo-WÖRld-wörld"
bool(true)
string(4) "#{a}"
bool(true)
string(6) "n-five"
bool(true)
string(1) "X"
bool(true)
string(4) "#{b}"
bool(true)
string(3) "7+7"
bool(true)
string(14) "62696e2d002d79"
bool(true)
string(5) "Array"
Error
Error
bool(true)
