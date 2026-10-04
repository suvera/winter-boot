<?php
// Benchmark: inline-code compilation cache + template expansion.
//
// Usage:
//   PHP_INI_SCAN_DIR=/tmp/wbini php php-ext/benchmark/bench_expand.php [iterations]
//   (default ini carries the pre-change build, so the same script run under
//   each ini compares old vs new for the exec_inline half.)
//
// Part A times repeated winter_boot_exec_inline() of one code string: the
// old build recompiles every call, the new build compiles once.
// Part B times one key-build substitution: str_replace() with search/replace
// arrays (the former tail of buildNameByContext) vs winter_boot_expand_template().

$iterations = (int) ($argv[1] ?? 20000);

function measure(callable $fn, int $n): float {
    for ($i = 0; $i < 1000; $i++) {
        $fn($i);
    }
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn($i);
    }
    return (hrtime(true) - $start) / 1e9;
}

$hasInline = function_exists('winter_boot_exec_inline');
$hasExpand = function_exists('winter_boot_expand_template');
echo 'winter_boot_exec_inline: ' . ($hasInline ? 'yes' : 'no') . "\n";
echo 'winter_boot_expand_template: ' . ($hasExpand ? 'yes' : 'no') . "\n";

// Part A: same code string, fresh vars per call (the cache-key-template shape).
$code = 'return $id;';
$timeInline = measure(function ($i) use ($code) {
    winter_boot_exec_inline($code, ['id' => $i]);
}, $iterations);
printf("exec_inline x%d: %.3f s (%.1f us/call)\n", $iterations, $timeInline, $timeInline / $iterations * 1e6);

// Part B: substitution only.
$template = 'order-#{id}-#{u}-#{id}';
$pairs = ['#{id}' => 0, '#{u}' => 'u-9'];
$timeStrReplace = measure(function ($i) use ($template, $pairs) {
    $pairs['#{id}'] = $i;
    str_replace(array_keys($pairs), array_values($pairs), $template);
}, $iterations * 5);
if ($hasExpand) {
    $timeExpand = measure(function ($i) use ($template, $pairs) {
        $pairs['#{id}'] = $i;
        winter_boot_expand_template($template, $pairs);
    }, $iterations * 5);
    printf(
        "substitution x%d: str_replace %.3f s vs expand_template %.3f s\n",
        $iterations * 5, $timeStrReplace, $timeExpand
    );
} else {
    printf("substitution x%d: str_replace %.3f s (expand_template unavailable)\n", $iterations * 5, $timeStrReplace);
}
