<?php

namespace EasyCo\Media\Storage;

use DateTimeImmutable;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\StoredFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Laravel Filesystem/Flysystem-backed MediaStorageAdapter — see
 * media-domain-design.md §5. Lives in its own Storage/ folder, not
 * Persistence/Eloquent/: this is filesystem infrastructure, a
 * different kind of infrastructure concern from DB persistence, so a
 * separate folder keeps the distinction clear rather than overloading
 * "Persistence" to mean both.
 *
 * $defaultDisk IS INJECTED AS A PLAIN STRING, NOT READ VIA config()
 * IN THIS CLASS: mirrors ProductMediaCountGuard's exact posture, for
 * consistency across the project, even though this class is already
 * not framework-agnostic in the strict sense (it touches the Storage
 * facade directly, by design — §5). The one place config() is
 * actually read is MediaServiceProvider.
 */
final class LaravelMediaStorageAdapter implements MediaStorageAdapter
{
    public function __construct(
        private readonly string $defaultDisk,
    ) {
    }

    public function store(string $content, string $originalFilename, ?string $disk = null, ?DateTimeImmutable $at = null): StoredFile
    {
        $resolvedDisk = $disk ?? $this->defaultDisk;
        $at ??= new DateTimeImmutable();

        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $uniqueFilename = Str::uuid()->toString().($extension !== '' ? ".{$extension}" : '');
        $path = 'uploads/'.$at->format('Y/m')."/{$uniqueFilename}";

        Storage::disk($resolvedDisk)->put($path, $content);

        return new StoredFile($resolvedDisk, $path);
    }

    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }
}
