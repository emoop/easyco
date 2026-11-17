<?php

/**
 * CI-1: a short Markdown summary of one shard from its JUnit XML (for $GITHUB_STEP_SUMMARY).
 *   php scripts/ci/summary.php <junit.xml> <shard-label>
 * Prints: tests / passed / failed / skipped, then the names of the failing tests. Exit 0 always (the test step decides red or green).
 */
[, $path, $label] = $argv + [null, '', 'shard'];

if (! is_file((string) $path)) {
    echo "### {$label}\nNo JUnit file was written (PHPUnit stopped before the report).\n";
    exit(0);
}

$xml = simplexml_load_file($path);
$tests = $failed = $skipped = 0;
$names = [];
foreach ($xml->xpath('//testcase') as $case) {
    $tests++;
    if (isset($case->failure) || isset($case->error)) {
        $failed++;
        $names[] = ($case['class'] ?? $case['classname'] ?? '').'::'.$case['name'];
    } elseif (isset($case->skipped)) {
        $skipped++;
    }
}

echo "### {$label}\n\n| tests | passed | failed | skipped |\n|---|---|---|---|\n| {$tests} | ".($tests - $failed - $skipped)." | {$failed} | {$skipped} |\n";
if ($names !== []) {
    echo "\n**Failing tests**\n\n";
    foreach ($names as $name) {
        echo "- `{$name}`\n";
    }
}
