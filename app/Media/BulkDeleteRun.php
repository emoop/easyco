<?php

namespace App\Media;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The state of the ONE «delete all unused of this filter» run that may be going at a time (ML-1b): a cache lock (so a second click says «вече върви») and a progress record
 * `media.bulk-delete.{runId}` the page polls: `total`, `done`, `skipped`, `finished`, and `error` when the job itself died.
 */
final class BulkDeleteRun
{
    public const LOCK = 'media.bulk-delete.lock';

    private const LOCK_SECONDS = 21600;

    private const TTL_SECONDS = 86400;

    /** Takes the lock and writes the first progress record; null when a run is already going. @param  array<string, mixed>  $filters */
    public static function start(array $filters, int $total, ?int $staffId): ?string
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        $runId = (string) Str::uuid();
        Cache::put(self::key($runId), ['total' => $total, 'done' => 0, 'skipped' => 0, 'finished' => false, 'error' => null, 'lock' => $lock->owner(), 'staff' => $staffId, 'filters' => $filters], self::TTL_SECONDS);

        return $runId;
    }

    /** @return array{total: int, done: int, skipped: int, finished: bool, error: ?string}|null */
    public static function progress(string $runId): ?array
    {
        $data = Cache::get(self::key($runId));

        return is_array($data) ? array_intersect_key($data, array_flip(['total', 'done', 'skipped', 'finished', 'error'])) : null;
    }

    public static function update(string $runId, int $done, int $skipped): void
    {
        $data = Cache::get(self::key($runId));

        is_array($data) && Cache::put(self::key($runId), [...$data, 'done' => $done, 'skipped' => $skipped], self::TTL_SECONDS);
    }

    /** Marks the run finished and lets the next one start. */
    public static function finish(string $runId, ?string $error = null): void
    {
        $data = Cache::get(self::key($runId));

        if (is_array($data)) {
            Cache::put(self::key($runId), [...$data, 'finished' => true, 'error' => $error], self::TTL_SECONDS);
            is_string($data['lock'] ?? null) && Cache::restoreLock(self::LOCK, $data['lock'])->release();
        }
    }

    public static function running(): bool
    {
        $lock = Cache::lock(self::LOCK, 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    private static function key(string $runId): string
    {
        return 'media.bulk-delete.'.$runId;
    }
}
