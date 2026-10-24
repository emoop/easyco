<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan media:sweep-files` — ML-1b (media-library-design.md §5): files under `uploads/` that NO row of `catalog_media` refers to and that are older than 24 hours: what an abandoned form
 * upload leaves behind (a Filament `FileUpload` stores the bytes at once, the row is created only when the form is saved).
 *
 * A file is referenced when it is a row's original or one of its listed variants, or a `{same-base}-{tier}.webp` next to a row's original (a stray variant of a live asset is that asset's, not
 * abandoned). DRY-RUN BY DEFAULT: counts and total size; `--delete` removes them. Never looks outside `uploads/` (not `html-blocks/`, not `appearance/`, not the `bench/` rows). The rows are read
 * in chunks and only their paths are kept, never the models. Counts only: no file name is printed.
 */
class SweepMediaFiles extends Command
{
    protected $signature = 'media:sweep-files {--delete : Remove the files (the default is a dry-run that changes nothing)}';

    protected $description = 'List (or with --delete remove) files under uploads/ older than 24 hours that no media asset refers to (dry-run unless --delete)';

    private const MIN_AGE_HOURS = 24;

    private const TIERS = '(?:thumbnail|medium|large|admin_grid)';

    public function handle(): int
    {
        $delete = (bool) $this->option('delete');
        $disk = (string) config('services.media.default_disk', 'public');
        $storage = Storage::disk($disk);

        [$exact, $bases] = $this->referenced($disk);
        $cutoff = now()->subHours(self::MIN_AGE_HOURS)->getTimestamp();
        $found = 0;
        $bytes = 0;
        $removed = 0;
        $young = 0;

        foreach ($storage->allFiles('uploads') as $file) {
            if (! str_starts_with($file, 'uploads/') || isset($exact[$file]) || $this->isVariantOfLiveOriginal($file, $bases)) {
                continue;
            }

            if ($storage->lastModified($file) > $cutoff) {
                $young++;

                continue;
            }

            $found++;
            $bytes += (int) $storage->size($file);

            if ($delete && $storage->delete($file)) {
                $removed++;
            }
        }

        $this->line($delete ? 'Изпълнение:' : 'Проба (нищо не се трие):');
        $this->line(sprintf('  изоставени файлове в uploads/ (по-стари от %d часа): %d, общо %s', self::MIN_AGE_HOURS, $found, $this->size($bytes)));
        $this->line(sprintf('  по-нови от %d часа (не се пипат): %d', self::MIN_AGE_HOURS, $young));
        $delete && $this->line(sprintf('  изтрити: %d', $removed));
        Log::info('media.sweep_files', ['delete' => $delete, 'found' => $found, 'removed' => $removed, 'bytes' => $bytes]);

        return self::SUCCESS;
    }

    /**
     * The paths the rows refer to, read in chunks (only strings are kept).
     *
     * @return array{0: array<string, true>, 1: array<string, true>} every original and variant path, and the `directory/base` of every original
     */
    private function referenced(string $disk): array
    {
        $exact = [];
        $bases = [];

        DB::table('catalog_media')->where('disk', $disk)->orderBy('id')->chunkById(1000, function ($rows) use (&$exact, &$bases): void {
            foreach ($rows as $row) {
                $path = (string) $row->path;
                $exact[$path] = true;
                $bases[$this->base($path)] = true;

                foreach ((array) json_decode((string) $row->variants, true) as $variant) {
                    is_array($variant) && is_string($variant['path'] ?? null) && $exact[$variant['path']] = true;
                }
            }
        });

        return [$exact, $bases];
    }

    /** `uploads/2026/10/abc.jpg` → `uploads/2026/10/abc` */
    private function base(string $path): string
    {
        $info = pathinfo($path);

        return ($info['dirname'] ?? '.') === '.' ? $info['filename'] : $info['dirname'].'/'.$info['filename'];
    }

    /** @param  array<string, true>  $bases */
    private function isVariantOfLiveOriginal(string $file, array $bases): bool
    {
        return preg_match('/^(.*)-'.self::TIERS.'\.webp$/', $file, $m) === 1 && isset($bases[$m[1]]);
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 2).' MB' : number_format($bytes / 1024, 1).' KB';
    }
}
