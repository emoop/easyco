<?php

namespace EasyCo\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes the FILES of a media asset whose row is already gone (ML-1b, media-library-design.md ML-D4): the original, every variant path the row listed, and any `{same-base}-{tier}.webp` left
 * next to the original by an earlier failed processing (the directory is listed ONCE). Dispatched after the delete COMMITTED, so a row never points at nothing; a file that is not there is fine
 * (a row whose file was already missing is deleted without a trace); a failure is logged and retried (`tries`, backoff) and never brings the row back.
 *
 * Takes plain paths, not an id: the row no longer exists. Refuses an empty path or one with `..`.
 */
class DeleteMediaFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> seconds */
    public array $backoff = [10, 60];

    /** @param  list<string>  $variantPaths */
    public function __construct(
        private readonly string $disk,
        private readonly string $path,
        private readonly array $variantPaths = [],
    ) {
    }

    public function handle(): void
    {
        $storage = Storage::disk($this->disk);
        $paths = [];

        foreach ([$this->path, ...$this->variantPaths] as $path) {
            self::safe($path) && $paths[$path] = true;
        }

        if (self::safe($this->path)) {
            $directory = trim(str_replace(chr(92), '/', dirname($this->path)), '/.');
            $base = pathinfo($this->path, PATHINFO_FILENAME);

            // a failed processing can leave `{base}-thumbnail.webp` and friends that the row never listed
            foreach ($storage->files($directory === '' ? '' : $directory) as $file) {
                preg_match('/^'.preg_quote($base, '/').'-[A-Za-z0-9_]+\.webp$/', basename($file)) === 1 && $paths[$file] = true;
            }
        }

        $failed = [];

        foreach (array_keys($paths) as $path) {
            try {
                $storage->exists($path) && $storage->delete($path);
            } catch (Throwable $e) {
                $failed[] = $path;
            }
        }

        if ($failed !== []) {
            Log::warning('media: some files of a deleted asset could not be removed', ['disk' => $this->disk, 'count' => count($failed)]);

            throw new \RuntimeException('Could not remove '.count($failed).' media file(s).');
        }
    }

    private static function safe(string $path): bool
    {
        return $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/');
    }
}
