<?php

namespace App\Console\Commands;

use EasyCo\Media\Exceptions\ImageNotCleanableException;
use EasyCo\Media\Image\MetadataStripper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `php artisan media:strip-metadata` — ML-0 (media-library-design.md ML-D8): removes EXIF / GPS / XMP / IPTC / comments from the ORIGINALS already stored (uploads since ML-0 are clean at upload).
 *
 * DRY-RUN BY DEFAULT: it reads every original once and reports what it WOULD do: how many files change and the bytes saved, how many would be re-encoded to keep their orientation, which ids are
 * malformed, cannot be decoded or need a conversion this command cannot do in place (HEIC / AVIF: a stored file keeps its name), and which files are missing. `--html-blocks` also scans the loose
 * files the HTML block editor wrote to `html-blocks/` (they are not media assets).
 *
 * `--execute` asks for confirmation (`--force` skips it), reminds to back up `storage/app/public`, then for each file that would change: strips it, VERIFIES the result (it decodes, and has the same
 * size as before: the width and height swapped for an orientation of 5 to 8) and writes it back to the SAME path, so every variant and every URL stays valid. A file that fails the check is left as it
 * was and reported. IDEMPOTENT: a second run changes nothing. It touches nothing but the originals of image assets and, with `--html-blocks`, that one folder: never a variant, never `appearance/`.
 * Counts and ids only: no metadata value is printed or logged.
 */
class StripMediaMetadata extends Command
{
    protected $signature = 'media:strip-metadata {--execute : Write the cleaned files (the default is a dry-run that changes nothing)} {--force : Do not ask for confirmation} {--html-blocks : Also scan the loose files of the HTML block editor (html-blocks/)}';

    protected $description = 'Remove EXIF / GPS / XMP / IPTC metadata from stored image originals (dry-run unless --execute)';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $stripper = new MetadataStripper;

        if ($execute) {
            $this->warn('Направете резервно копие на storage/app/public преди това.');

            if (! $this->option('force') && ! $this->confirm('Да презапиша ли оригиналите без метаданни?', false)) {
                $this->line('Спряно: нищо не е променено.');

                return self::SUCCESS;
            }
        }

        $report = ['files' => 0, 'would' => 0, 'saved' => 0, 'oriented' => 0, 'done' => 0, 'failed' => [], 'malformed' => [], 'undecodable' => [], 'missing' => [], 'clean' => 0];

        foreach (DB::table('catalog_media')->whereIn('type', ['image', 'social_image'])->orderBy('id')->get(['id', 'disk', 'path']) as $row) {
            $this->one($stripper, (string) $row->disk, (string) $row->path, 'asset #'.$row->id, $execute, $report);
        }

        if ($this->option('html-blocks')) {
            $disk = (string) config('services.media.default_disk', 'public');

            foreach (Storage::disk($disk)->files('html-blocks') as $path) {
                if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $this->one($stripper, $disk, $path, 'html-blocks file '.md5($path), $execute, $report);
                }
            }
        }

        $this->summary($report, $execute);
        Log::info('media.strip_metadata', ['execute' => $execute, 'files' => $report['files'], 'changed' => $execute ? $report['done'] : $report['would'], 'failed' => count($report['failed'])]);

        return $report['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $report */
    private function one(MetadataStripper $stripper, string $disk, string $path, string $name, bool $execute, array &$report): void
    {
        $report['files']++;
        $storage = Storage::disk($disk);

        if ($path === '' || ! $storage->exists($path)) {
            $report['missing'][] = $name;

            return;
        }

        $bytes = (string) $storage->get($path);

        try {
            $result = $stripper->strip($bytes, convert: false);
        } catch (ImageNotCleanableException) {
            $report['undecodable'][] = $name;

            return;
        }

        if ($result->malformed) {
            $report['malformed'][] = $name;

            return;
        }

        if ($result->needsConversion) {
            $report['undecodable'][] = $name;

            return;
        }

        if (! $result->changed) {
            $report['clean']++;

            return;
        }

        $report['would']++;
        $report['saved'] += strlen($bytes) - strlen($result->bytes);
        $result->reencoded && $report['oriented']++;

        if (! $execute) {
            return;
        }

        if (! $this->verified($bytes, $result->bytes, $result->orientation)) {
            $report['failed'][] = $name;

            return;
        }

        $storage->put($path, $result->bytes);
        $report['done']++;
    }

    /** The cleaned bytes decode and have the size the picture had (swapped when an orientation of 5 to 8 turned it). */
    private function verified(string $before, string $after, int $orientation): bool
    {
        try {
            $was = getimagesizefromstring($before);
            $now = getimagesizefromstring($after);

            if ($was === false || $now === false) {
                return false;
            }

            [$w, $h] = $orientation >= 5 && $orientation <= 8 ? [$was[1], $was[0]] : [$was[0], $was[1]];
            $decoded = Image::fromBytes($after);

            return $w > 0 && $h > 0 && $now[0] === $w && $now[1] === $h && $decoded->width() === $w && $decoded->height() === $h;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $report */
    private function summary(array $report, bool $execute): void
    {
        $this->line($execute ? 'Изпълнение:' : 'Проба (нищо не се променя):');
        $this->line(sprintf('  прегледани файлове: %d', $report['files']));
        $this->line(sprintf('  вече чисти: %d', $report['clean']));
        $this->line(sprintf('  %s: %d (спестени %s)', $execute ? 'почистени' : 'ще бъдат почистени', $execute ? $report['done'] : $report['would'], $this->bytes((int) $report['saved'])));
        $this->line(sprintf('  от тях с обръщане по ориентация (прекодиране): %d', $report['oriented']));
        $this->line(sprintf('  повредени (не се пипат): %d%s', count($report['malformed']), $this->ids($report['malformed'])));
        $this->line(sprintf('  не могат да се прочетат или се нуждаят от конвертиране (не се пипат): %d%s', count($report['undecodable']), $this->ids($report['undecodable'])));
        $this->line(sprintf('  липсващи файлове: %d%s', count($report['missing']), $this->ids($report['missing'])));

        if ($execute) {
            $this->line(sprintf('  пропуснати, защото проверката не мина: %d%s', count($report['failed']), $this->ids($report['failed'])));
        }
    }

    /** @param  list<string>  $names */
    private function ids(array $names): string
    {
        return $names === [] ? '' : ' ['.implode(', ', array_slice($names, 0, 30)).(count($names) > 30 ? ', …' : '').']';
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 2).' MB' : number_format($bytes / 1024, 1).' KB';
    }
}
