<?php
// Benchmark: native winter_boot deferred() vs a userland closure-stack equivalent.
//
// Usage:
//   php -n -d extension=/path/to/winter_boot.so benchmark/bench.php [iterations]
//
// The userland baseline mirrors what deferred() does: push closures onto a stack
// and drain it LIFO in a finally block. Both variants run identical payloads.

$iterations = (int) ($argv[1] ?? 200000);

function nativeWork($i) {
    deferred(function () {});
    deferred(function () use ($i) { $x = $i; });
    return $i;
}

function userlandWork($i) {
    $stack = [];
    try {
        $stack[] = function () {};
        $stack[] = function () use ($i) { $x = $i; };
        return $i;
    } finally {
        while ($cb = array_pop($stack)) {
            $cb();
        }
    }
}

function measure(callable $fn, int $n): float {
    // Warmup.
    for ($i = 0; $i < 1000; $i++) {
        $fn($i);
    }
    $start = hrtime(true);
    $acc = 0;
    for ($i = 0; $i < $n; $i++) {
        $acc += $fn($i);
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    // Prevent the loop from being optimized away.
    if ($acc < 0) {
        echo "impossible\n";
    }
    return $elapsed;
}

if (!function_exists('deferred')) {
    fwrite(STDERR, "winter_boot extension not loaded\n");
    exit(1);
}

$native = measure('nativeWork', $iterations);
$userland = measure('userlandWork', $iterations);

printf("iterations : %d (2 cleanups each)\n", $iterations);
printf("native     : %.4f s (%.0f ops/s)\n", $native, $iterations / $native);
printf("userland   : %.4f s (%.0f ops/s)\n", $userland, $iterations / $userland);
printf("overhead   : native is %.2fx userland time\n", $native / $userland);
