<?php

namespace App\Media;

use App\Services\ActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * «Изтрий всички неизползвани от този филтър» (ML-1b): the filter VALUES, not a list of ids, travel to the queue. The job runs the same query again (MediaFilters), in chunks of 500 by id, and
 * deletes every row through MediaDeleter, so each one is locked and checked against every usage provider at the moment it is deleted; a picture that became used in the meantime is skipped. It
 * refuses to run unless the filter says `use = unused`. Progress goes to the cache (BulkDeleteRun) for the page to show; ONE activity-log line (counts and the filter in words) at the end.
 */
class DeleteUnusedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHUNK = 500;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param  array<string, mixed>  $filters */
    public function __construct(
        private readonly string $runId,
        private readonly array $filters,
        private readonly ?int $staffId,
    ) {
    }

    public function handle(MediaDeleter $deleter, ActivityLogger $log): void
    {
        if (($this->filters['use'] ?? null) !== 'unused') {
            BulkDeleteRun::finish($this->runId, 'the filter does not say «неизползвани»');

            return;
        }

        $done = 0;
        $skipped = 0;

        MediaFilters::query($this->filters)->select('catalog_media.id')->chunkById(self::CHUNK, function ($rows) use ($deleter, &$done, &$skipped): void {
            foreach ($rows as $row) {
                $outcome = $deleter->delete((int) $row->id, $this->staffId);
                $outcome->wasDeleted() ? $done++ : $skipped++;

                ($done + $skipped) % 25 === 0 && BulkDeleteRun::update($this->runId, $done, $skipped);
            }

            BulkDeleteRun::update($this->runId, $done, $skipped);
        }, 'catalog_media.id', 'id');

        BulkDeleteRun::update($this->runId, $done, $skipped);
        $log->logDeleted('media', 'bulk-'.$this->runId, ['deleted' => $done, 'skipped_as_used' => $skipped, 'filter' => self::describe($this->filters), 'staff_id' => $this->staffId]);
        BulkDeleteRun::finish($this->runId);
    }

    public function failed(Throwable $e): void
    {
        BulkDeleteRun::finish($this->runId, 'the run stopped: '.mb_substr($e->getMessage(), 0, 200));
    }

    /** The filter in words, for the log. @param  array<string, mixed>  $filters */
    public static function describe(array $filters): string
    {
        $parts = [];

        foreach (['kind', 'use', 'state', 'month'] as $key) {
            $value = $filters[$key] ?? null;
            is_string($value) && $value !== '' && $parts[] = __('media.library.filters.'.$key).': '.(match ($key) {
                'kind' => __('media.library.kind.'.$value),
                'use' => __('media.library.use.'.$value),
                'state' => __('media.library.state.'.$value),
                default => $value,
            });
        }

        return implode('; ', $parts);
    }
}
