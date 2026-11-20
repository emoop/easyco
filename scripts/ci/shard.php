<?php

/**
 * CI-1: splits the PHPUnit suite into shards by test FILE, deterministically.
 *
 *   php scripts/ci/shard.php --verify [--shards=4]
 *       the shards together must be exactly the full list, each file once; prints the file count of every shard; exit 1 otherwise
 *   php scripts/ci/shard.php --shard=K [--shards=4] [--limit=N] [--config=PATH]
 *       writes a PHPUnit config (the project's phpunit.xml with <testsuites> replaced by the shard's files) to PATH
 *       (default phpunit.shard.xml) and prints the shard's files count; --limit keeps only the first N files (a local smoke run)
 *   php scripts/ci/shard.php --list
 *       prints the full sorted list
 *
 * The list = every *Test.php under the directories of phpunit.xml's testsuites (the same rule PHPUnit uses), sorted by path.
 * Shard k (1-based) takes the files whose index i satisfies i % shards == k - 1.
 */
// CI-2: the two functions below are also called by tests/Unit/Ci/ShardCoverageTest.php, which includes this file: only a run of the script itself goes on to the command line part.
// (function declarations are hoisted, so they exist even though this return comes first)
if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$root = dirname(__DIR__, 2);
$options = getopt('', ['verify', 'list', 'shard:', 'shards:', 'limit:', 'config:']);
$shards = max(1, (int) ($options['shards'] ?? 4));

/** @return list<string> the sorted *Test.php files (paths relative to the root, forward slashes) */
function suiteFiles(string $root): array
{
    $xml = simplexml_load_file($root.'/phpunit.xml');
    $files = [];
    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $pattern = $root.'/'.trim((string) $directory, '/');
            $suffix = (string) ($directory['suffix'] ?? 'Test.php');
            // a directory may be a glob (packages/EasyCo/*/tests), the way PHPUnit reads it
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $path) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    }
                }
            }
        }
        foreach ($suite->file as $file) {
            $files[] = trim((string) $file);
        }
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    return $files;
}

/** @param list<string> $all @return list<string> */
function shardOf(array $all, int $k, int $shards): array
{
    return array_values(array_filter($all, static fn (int $i): bool => $i % $shards === $k - 1, ARRAY_FILTER_USE_KEY));
}

$all = suiteFiles($root);

if (isset($options['list'])) {
    echo implode("\n", $all), "\n";
    exit(0);
}

if (isset($options['verify'])) {
    $merged = [];
    $counts = [];
    for ($k = 1; $k <= $shards; $k++) {
        $part = shardOf($all, $k, $shards);
        $counts[] = count($part);
        $merged = array_merge($merged, $part);
    }
    sort($merged, SORT_STRING);
    $ok = $all !== [] && $merged === $all && count($merged) === count(array_unique($merged));
    echo 'files: '.count($all).' | shards: '.implode(' + ', $counts)."\n";
    echo $ok ? "OK\n" : "FAIL: the shards are not exactly the full list\n";
    exit($ok ? 0 : 1);
}

$k = (int) ($options['shard'] ?? 0);
if ($k < 1 || $k > $shards) {
    fwrite(STDERR, "usage: php scripts/ci/shard.php --verify | --list | --shard=K [--shards=4] [--limit=N] [--config=PATH]\n");
    exit(2);
}

$mine = shardOf($all, $k, $shards);
isset($options['limit']) && $mine = array_slice($mine, 0, max(0, (int) $options['limit']));

$config = file_get_contents($root.'/phpunit.xml');
$list = '<testsuites><testsuite name="Shard '.$k.'">'.implode('', array_map(
    static fn (string $f): string => '<file>'.htmlspecialchars($f, ENT_XML1).'</file>',
    $mine,
)).'</testsuite></testsuites>';
$out = preg_replace('~<testsuites>.*?</testsuites>~s', $list, $config, 1, $replaced);
if ($replaced !== 1) {
    fwrite(STDERR, "phpunit.xml has no <testsuites> block to replace\n");
    exit(1);
}
file_put_contents($options['config'] ?? $root.'/phpunit.shard.xml', $out);
echo 'shard '.$k.'/'.$shards.': '.count($mine)." files\n";
