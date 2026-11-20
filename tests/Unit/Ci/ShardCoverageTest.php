<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * CI-2: every test file that exists on disk runs in CI, in exactly one shard. The files are found here independently of phpunit.xml (the three places tests
 * live: tests/Unit, tests/Feature and packages/EasyCo/<Package>/tests); the shards are built by the SAME functions scripts/ci/shard.php uses, with 4 shards.
 * A suite missing from phpunit.xml (the packages' tests were out of CI for months, and OrderTransitionsTest broke unnoticed) makes this red.
 */
class ShardCoverageTest extends TestCase
{
    private const SHARDS = 4;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private static function filesOnDisk(): array
    {
        $root = self::root();
        $files = [];

        foreach (array_merge([$root.'/tests/Unit', $root.'/tests/Feature'], glob($root.'/packages/EasyCo/*/tests', GLOB_ONLYDIR) ?: []) as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<list<string>> the files of shard 1..SHARDS, from the script's own functions */
    private static function shards(): array
    {
        require_once self::root().'/scripts/ci/shard.php';

        $all = \suiteFiles(self::root());
        $shards = [];

        for ($k = 1; $k <= self::SHARDS; $k++) {
            $shards[] = \shardOf($all, $k, self::SHARDS);
        }

        return $shards;
    }

    public function test_every_test_file_on_disk_is_in_exactly_one_shard(): void
    {
        $inShards = array_merge(...self::shards());
        $onDisk = self::filesOnDisk();

        $this->assertNotEmpty($onDisk);
        $this->assertSame([], array_values(array_diff($onDisk, $inShards)), 'test files that no shard runs (is the suite in phpunit.xml?)');
        $this->assertSame([], array_values(array_diff($inShards, $onDisk)), 'shard entries that are not test files of the three known places');
        $this->assertSame([], array_keys(array_filter(array_count_values($inShards), static fn (int $n): bool => $n > 1)), 'files in more than one shard');
        $this->assertCount(count($onDisk), $inShards);
    }

    public function test_the_package_tests_are_in_the_shards_and_spread_over_them(): void
    {
        $perShard = array_map(static fn (array $files): int => count(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'packages/EasyCo/'))), self::shards());

        $this->assertGreaterThan(0, array_sum($perShard), 'the packages suite is not in phpunit.xml');

        foreach ($perShard as $k => $count) {
            $this->assertGreaterThan(0, $count, 'shard '.($k + 1).' has no package test');
        }
    }
}
