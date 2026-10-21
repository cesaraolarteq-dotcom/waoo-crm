<?php

/**
 * Writes a PHPUnit configuration that runs one shard of the suite (CI only).
 * Usage: php tools/ci-shard.php <total> <index 1..total> > phpunit-shard.xml
 *
 * Every test file under tests/Unit and tests/Feature lands in exactly one shard. Files are balanced
 * by their number of tests, with extra weight for real two-process concurrency tests (the slowest).
 * The rest of phpunit.xml (environment, bootstrap, source) is copied unchanged.
 */
[$script, $total, $index] = $argv + [null, null, null];
$total = (int) $total;
$index = (int) $index;
if ($total < 1 || $index < 1 || $index > $total) {
    fwrite(STDERR, "usage: php tools/ci-shard.php <total> <index>\n");
    exit(1);
}

$root = dirname(__DIR__);
$files = [];
foreach (['tests/Unit', 'tests/Feature'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (str_ends_with($f->getFilename(), 'Test.php')) {
            $files[] = substr($f->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);

$weight = function (string $path) use ($root): int {
    $src = file_get_contents($root.'/'.$path);
    $tests = preg_match_all('/function\s+test_|#\[Test\]|@test\b/', $src) ?: 1;
    $concurrent = preg_match_all('/ConcurrentWorkers|pcntl_fork|proc_open/', $src);

    return $tests + 6 * $concurrent;
};

// Greedy: heaviest file first into the lightest shard (deterministic for the same file set).
$weighted = array_map(fn ($f) => [$f, $weight($f)], $files);
usort($weighted, fn ($a, $b) => [$b[1], $a[0]] <=> [$a[1], $b[0]]);
$bins = array_fill(1, $total, ['load' => 0, 'files' => []]);
foreach ($weighted as [$file, $w]) {
    $target = 1;
    foreach ($bins as $i => $bin) {
        if ($bin['load'] < $bins[$target]['load']) $target = $i;
    }
    $bins[$target]['load'] += $w;
    $bins[$target]['files'][] = $file;
}

$assigned = array_merge(...array_column($bins, 'files'));
if (count($assigned) !== count($files) || count(array_unique($assigned)) !== count($files)) {
    fwrite(STDERR, "shard assignment lost or duplicated a test file\n");
    exit(1);
}

$mine = $bins[$index]['files'];
sort($mine);
$xml = file_get_contents($root.'/phpunit.xml');
$suite = "<testsuites>\n        <testsuite name=\"Shard {$index} of {$total}\">\n"
    .implode('', array_map(fn ($f) => '            <file>'.htmlspecialchars($f, ENT_XML1)."</file>\n", $mine))
    ."        </testsuite>\n    </testsuites>";
$xml = preg_replace('#<testsuites>.*?</testsuites>#s', $suite, $xml, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "phpunit.xml has no <testsuites> block\n");
    exit(1);
}
fwrite(STDERR, sprintf("shard %d/%d: %d files, weight %d (of %d files)\n", $index, $total, count($mine), $bins[$index]['load'], count($files)));
echo $xml;
