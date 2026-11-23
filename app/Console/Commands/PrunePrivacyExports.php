<?php

namespace App\Console\Commands;

use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan privacy:prune-exports` (ACC-4c, acc-4-gdpr-design.md §5, daily): a personal-data export lives 7 days. For every request whose `export_expires_at` has passed it deletes the file,
 * sets `export_path` NULL and the status `expired`. It also removes ORPHAN ZIPs: files in `privacy-exports/` older than ORPHAN_DAYS that no request points to (a build that stored the file
 * and then died before writing the row). The grace of 8 days is longer than the export's life, so a file a running build has just stored is never taken. IDEMPOTENT: a second run finds nothing.
 */
class PrunePrivacyExports extends Command
{
    public const ORPHAN_DAYS = 8;

    protected $signature = 'privacy:prune-exports';

    protected $description = 'Delete expired personal-data export files (and orphan ZIPs) from the private disk';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $expired = 0;

        PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_EXPORT)
            ->whereNotNull('export_path')
            ->where('export_expires_at', '<', now())
            ->orderBy('id')
            ->each(function (PrivacyRequestModel $request) use ($disk, &$expired): void {
                $disk->delete((string) $request->export_path);
                $request->update(['export_path' => null, 'status' => PrivacyRequestModel::STATUS_EXPIRED]);
                $expired++;
            });

        $referenced = array_flip(PrivacyRequestModel::query()->whereNotNull('export_path')->pluck('export_path')->all());
        $limit = now()->subDays(self::ORPHAN_DAYS)->getTimestamp();
        $orphans = 0;

        foreach ($disk->files(BuildPrivacyExportJob::DIRECTORY) as $file) {
            if (! isset($referenced[$file]) && $disk->lastModified($file) < $limit) {
                $disk->delete($file);
                $orphans++;
            }
        }

        $this->info("Expired {$expired} export(s); removed {$orphans} orphan file(s).");

        return self::SUCCESS;
    }
}
