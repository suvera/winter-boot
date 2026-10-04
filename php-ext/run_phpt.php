<?php
// Minimal PHPT runner for the winter_boot suite.
//
// Usage: php run_phpt.php [path-to-tests]
// Runs every *.phpt with --SKIPIF-- / --FILE-- / --EXPECT-- sections using
// the current PHP binary. Standard php-src run-tests.php can run these files
// unchanged; this runner exists so the suite works without a php-src checkout.

$testDir = $argv[1] ?? __DIR__ . '/tests';
$ext = getenv('WINTER_BOOT_SO') ?: __DIR__ . '/modules/winter_boot.so';
$files = glob(rtrim($testDir, '/') . '/*.phpt');
sort($files);

$pass = 0;
$fail = 0;
$skip = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $name = basename($file);
    if (!preg_match('/--TEST--\s*\n(.*?)\n--/s', $src, $m)) {
        echo "INVALID $name (no --TEST--)\n";
        $fail++;
        continue;
    }
    $sections = [];
    foreach (['SKIPIF', 'FILE', 'EXPECT'] as $s) {
        if (preg_match('/--' . $s . "--\s*\n(.*?)(?=\n--[A-Z]+--|\$)/s", $src, $m)) {
            $sections[$s] = rtrim($m[1], "\n") . "\n";
        }
    }
    if (!isset($sections['FILE'], $sections['EXPECT'])) {
        echo "INVALID $name (needs --FILE-- and --EXPECT--)\n";
        $fail++;
        continue;
    }

    $run = function (string $code) use ($ext): array {
        $tmp = tempnam(sys_get_temp_dir(), 'wbphpt');
        file_put_contents($tmp, $code);
        $cmd = escapeshellarg(PHP_BINARY)
            . ' -n -d ' . escapeshellarg('extension=' . $ext)
            . ' ' . escapeshellarg($tmp) . ' 2>&1';
        exec($cmd, $out, $code_ret);
        unlink($tmp);
        return [implode("\n", $out) . "\n", $code_ret];
    };

    if (isset($sections['SKIPIF'])) {
        [$skipOut, $skipRet] = $run($sections['SKIPIF']);
        if (stripos($skipOut, 'skip') !== false) {
            echo "SKIP $name\n";
            $skip++;
            continue;
        }
    }

    [$actual, $ret] = $run($sections['FILE']);
    // NOTE: each --FILE-- body ends with a close tag; normalize trailing newline.
    $expected = $sections['EXPECT'];
    if ($actual === $expected) {
        echo "PASS $name\n";
        $pass++;
    } else {
        echo "FAIL $name\n";
        echo "--- expected ---\n$expected--- actual ---\n$actual---\n";
        $fail++;
    }
}

echo "\npass=$pass fail=$fail skip=$skip\n";
exit($fail > 0 ? 1 : 0);
