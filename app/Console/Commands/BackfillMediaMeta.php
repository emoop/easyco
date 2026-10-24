<?php

namespace App\Console\Commands;

use EasyCo\Media\MediaDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use finfo;

/**
 * `php artisan media:backfill-meta` — ML-1a (media-library-design.md §2): fills the details columns of `catalog_media` rows uploaded before they existed.
 *
 * DRY-RUN BY DEFAULT: reports what `--execute` would write. For a row with an empty column it reads the original ONCE (mime by `finfo`, never by the extension; size; pixels of an image) and fills
 * ONLY the empty columns; `original_name` is the stored file name when unknown. A row whose file is not on the disk gets `missing_at` (and its name); `missing_at` is cleared when the file is found
 * again, which is how «Медия» says «файлът липсва» without a disk call per card. IDEMPOTENT: a second run changes nothing. Chunked by id: the table is never loaded whole. Writes nothing but these
 * columns; never touches a file. Counts only: no file name is printed.
 */
class BackfillMediaMeta extends Command
{
    protected $signature = 'media:backfill-meta {--execute : Write the columns (the default is a dry-run that changes nothing)}';

    protected $description = 'Fill the details columns (name, mime, size, pixels, missing file) of catalog_media rows uploaded before they existed (dry-run unless --execute)';

    private const CHUNK = 500;

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $report = ['examined' => 0, 'filled' => 0, 'newly_missing' => 0, 'found_again' => 0, 'missing' => 0, 'unchanged' => 0];

        DB::table('catalog_media')
            ->where(fn ($q) => $q->whereNull('original_name')->orWhereNull('mime')->orWhereNull('size_bytes')->orWhere(fn ($i) => $i->whereIn('type', ['image', 'social_image'])->where(fn ($w) => $w->whereNull('width')->orWhereNull('height')))->orWhereNotNull('missing_at'))
            ->chunkById(self::CHUNK, function ($rows) use ($execute, $finfo, &$report): void {
                foreach ($rows as $row) {
                    $report['examined']++;
                    $changes = $this->changes($row, $finfo, $report);

                    if ($changes === []) {
                        $report['unchanged']++;

                        continue;
                    }

                    $execute && DB::table('catalog_media')->where('id', $row->id)->update($changes);
                }
            });

        $this->line($execute ? 'Изпълнение:' : 'Проба (нищо не се променя):');
        $this->line(sprintf('  прегледани редове: %d', $report['examined']));
        $this->line(sprintf('  %s: %d', $execute ? 'допълнени' : 'ще бъдат допълнени', $report['filled']));
        $this->line(sprintf('  файлът липсва (отбелязани): %d, от тях нови: %d', $report['missing'], $report['newly_missing']));
        $this->line(sprintf('  файлът е намерен отново (отметката е махната): %d', $report['found_again']));
        $this->line(sprintf('  без промяна: %d', $report['unchanged']));
        Log::info('media.backfill_meta', ['execute' => $execute] + $report);

        return self::SUCCESS;
    }

    /**
     * The columns to write for one row (empty = nothing): only empty columns are filled, `missing_at` follows the file.
     *
     * @param  array<string, int>  $report
     * @return array<string, mixed>
     */
    private function changes(object $row, finfo $finfo, array &$report): array
    {
        $changes = [];
        $disk = Storage::disk((string) $row->disk);
        $path = (string) $row->path;
        $exists = $path !== '' && $disk->exists($path);
        $isImage = in_array($row->type, ['image', 'social_image'], true);

        $row->original_name === null && $changes['original_name'] = MediaDetails::cleanName(basename($path));

        if (! $exists) {
            $report['missing']++;
            if ($row->missing_at === null) {
                $changes['missing_at'] = now();
                $report['newly_missing']++;
            }
        } else {
            if ($row->missing_at !== null) {
                $changes['missing_at'] = null;
                $report['found_again']++;
            }

            if ($row->mime === null || $row->size_bytes === null || ($isImage && ($row->width === null || $row->height === null))) {
                $bytes = (string) $disk->get($path);
                $size = $isImage ? @getimagesizefromstring($bytes) : false;
                $mime = $finfo->buffer($bytes);

                $row->mime === null && is_string($mime) && $changes['mime'] = mb_substr($mime, 0, 50);
                $row->size_bytes === null && $changes['size_bytes'] = strlen($bytes);
                $row->width === null && $size !== false && $size[0] > 0 && $changes['width'] = $size[0];
                $row->height === null && $size !== false && $size[1] > 0 && $changes['height'] = $size[1];
            }
        }

        $filled = array_diff_key($changes, ['missing_at' => 1]);
        $filled !== [] && $report['filled']++;

        return array_filter($changes, fn ($v, $k) => $v !== null || $k === 'missing_at', ARRAY_FILTER_USE_BOTH);
    }
}
