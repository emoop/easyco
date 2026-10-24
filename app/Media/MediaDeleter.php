<?php

namespace App\Media;

use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Jobs\DeleteMediaFilesJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The ONE way a media asset is deleted (ML-1b, media-library-design.md §4, ML-D3 / ML-D4): a transaction that locks the `catalog_media` row, asks EVERY usage provider again whether the picture
 * is used anywhere (the JSON places no foreign key can guard: HTML block pictures, design zones), and only if it is not, deletes the row through the domain repository. The files go AFTER the
 * commit (DeleteMediaFilesJob), so a row never points at nothing. The database itself is the last guard: a product, variation or brand that still shows the picture holds a RESTRICT foreign key,
 * and the refusal (SQLSTATE 23000, never matched by message: CLAUDE.md rule 3) is turned into `in_use` too.
 *
 * The usage registry is built fresh for every call: its providers remember what they read, and a re-check inside the lock must see the present state.
 */
final class MediaDeleter
{
    public function __construct(private readonly MediaAssetRepository $assets)
    {
    }

    public function delete(int $id, ?int $staffId = null): DeleteOutcome
    {
        $files = null;

        try {
            $outcome = DB::transaction(function () use ($id, &$files): DeleteOutcome {
                $row = MediaAssetModel::query()->whereKey($id)->lockForUpdate()->first();

                if ($row === null) {
                    return DeleteOutcome::notFound();
                }

                $uses = (new MediaUsageRegistry)->counts([$id])[$id] ?? 0;

                if ($uses > 0) {
                    return DeleteOutcome::inUse($uses);
                }

                // a row whose file is known to be gone (`missing_at`, set by media:backfill-meta) has no files to remove: no job, so deleting thousands of such rows queues nothing
                $files = $row->missing_at !== null ? null : [(string) $row->disk, (string) $row->path, array_values(array_filter(array_map(fn ($v): ?string => is_array($v) ? ($v['path'] ?? null) : null, (array) $row->variants)))];
                $this->assets->delete((string) $id);

                return DeleteOutcome::deleted();
            });
        } catch (QueryException $e) {
            if ($this->isForeignKeyRefusal($e)) {
                return DeleteOutcome::inUse(1);
            }

            throw $e;
        }

        $outcome->wasDeleted() && $files !== null && DeleteMediaFilesJob::dispatch(...$files)->afterCommit();

        return $outcome;
    }

    /** SQLSTATE 23000 with the driver's own code: MySQL 1451 (a parent row is referenced), SQLite 19 (CLAUDE.md rule 3). */
    private function isForeignKeyRefusal(QueryException $e): bool
    {
        $info = $e->errorInfo ?? [];

        return ($info[0] ?? null) === '23000' && in_array((int) ($info[1] ?? 0), [1451, 19], true);
    }
}
