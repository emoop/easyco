<?php

namespace EasyCo\Media\Storage;

use DateTimeImmutable;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Image\MetadataStripper;
use EasyCo\Media\Image\StripResult;
use EasyCo\Media\StoredFile;
use Illuminate\Support\Facades\Log;
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
    private readonly MetadataStripper $stripper;

    public function __construct(
        private readonly string $defaultDisk,
        ?MetadataStripper $stripper = null,
    ) {
        $this->stripper = $stripper ?? new MetadataStripper();
    }

    /**
     * @throws \EasyCo\Media\Exceptions\ImageNotCleanableException a picture in a format this server cannot decode, so it cannot be cleaned (never stored)
     */
    public function store(string $content, string $originalFilename, ?string $disk = null, ?DateTimeImmutable $at = null): StoredFile
    {
        $resolvedDisk = $disk ?? $this->defaultDisk;
        $at ??= new DateTimeImmutable();

        // ML-0: an image original is stored WITHOUT its metadata (EXIF / GPS / XMP / IPTC / comments), decided by the content, never by the name. Video and what is not a picture pass through
        // untouched; an image that cannot be cleaned is refused (ImageNotCleanableException) and never stored. The extension follows what is stored (a converted picture is `.jpg`).
        $result = $this->stripper->strip($content);
        $content = $result->bytes;
        $extension = $this->extensionFor($originalFilename, $result);

        if ($result->changed || $result->malformed) {
            Log::debug('media: original stored', ['format' => $result->format, 'removed' => count($result->removed), 'removed_bytes' => $result->removedBytes, 'malformed' => $result->malformed, 'converted' => $result->converted, 'reencoded' => $result->reencoded]);
        }

        $uniqueFilename = Str::uuid()->toString().($extension !== '' ? ".{$extension}" : '');
        $path = 'uploads/'.$at->format('Y/m')."/{$uniqueFilename}";

        Storage::disk($resolvedDisk)->put($path, $content);

        return new StoredFile($resolvedDisk, $path);
    }

    /** The client's extension when it already names what was stored (`.jpeg` for a JPEG), else the one of the stored format, else the client's as it was. */
    private function extensionFor(string $originalFilename, StripResult $result): string
    {
        $client = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $own = $result->extension();

        if ($own === null) {
            return $client;
        }

        $same = strtolower($client) === $own || ($own === 'jpg' && strtolower($client) === 'jpeg');

        return $same ? $client : $own;
    }

    public function storeAt(string $content, string $disk, string $path): StoredFile
    {
        Storage::disk($disk)->put($path, $content);

        return new StoredFile($disk, $path);
    }

    public function get(string $disk, string $path): string
    {
        return Storage::disk($disk)->get($path);
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
