<?php

namespace EasyCo\Media\Image;

use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Exceptions\MediaProcessingException;
use EasyCo\Media\MediaVariant;
use Illuminate\Support\Facades\Image;
use Throwable;

/**
 * Illuminate\Image-backed MediaImageProcessor — see
 * media-domain-design.md §3. Lives in its own Image/ folder, mirroring
 * why Storage/ is separate from Persistence/Eloquent/: this touches an
 * entirely different kind of infrastructure (image processing) than
 * either DB persistence or filesystem storage.
 *
 * $variantConfig IS INJECTED AS A PLAIN ARRAY, NOT READ VIA config()
 * IN THIS CLASS: mirrors ProductMediaCountGuard's/
 * LaravelMediaStorageAdapter's exact posture — the one place config()
 * is actually read is MediaServiceProvider.
 */
final class LaravelMediaImageProcessor implements MediaImageProcessor
{
    public function __construct(
        private readonly MediaStorageAdapter $storageAdapter,
        private readonly array $variantConfig,
    ) {
    }

    /**
     * §3.1: no DriverInterface::supports() exists in Illuminate\Image
     * (confirmed by reading vendor/laravel/framework/src/Illuminate/Image/
     * directly — the Driver contract only has process()/dimensions()/
     * dominantColor()/transformUsing()). The only real signal is
     * attempting a decode and seeing whether it throws — so that's
     * what this does, via the cheapest real operation (orient() +
     * materializing via toBytes()).
     *
     * Catches Throwable, not just Illuminate\Image\ImageException: a
     * corrupted file can fail decoding with a ValueError or another
     * GD/Imagick-originated error that never gets wrapped into an
     * ImageException. Any failure here means "cannot process this,"
     * regardless of the exception's type.
     *
     * NOT called by generateVariants() below — that method does its
     * own single decode+orient (which doubles as this exact check)
     * rather than calling this and then decoding a second time. This
     * method stays public for external callers that only want the
     * support check itself.
     */
    public function supportsFormat(string $content): bool
    {
        try {
            Image::fromBytes($content)->orient()->toBytes();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function generateVariants(string $sourceContent, string $disk, string $originalPath): array
    {
        // One decode+orient for the whole asset, not one per tier (was
        // 5 total: one in the old separate supportsFormat() call, plus
        // one more per tier re-applying orient() from scratch). This
        // single materialization also doubles as the format-support
        // check — Illuminate\Image exposes no separate supports()
        // method (§3.1), so an unsupported/corrupt source fails here.
        try {
            $orientedContent = Image::fromBytes($sourceContent)->orient()->toBytes();
        } catch (Throwable) {
            throw MediaProcessingException::unsupportedFormat();
        }

        $basePath = self::pathWithoutExtension($originalPath);
        $storedPaths = [];

        try {
            $variants = [];

            foreach ($this->variantConfig as $tier => $config) {
                // Built from the already-oriented bytes — orient() is
                // deliberately not called again here.
                $image = Image::fromBytes($orientedContent);

                // scale(): fits inside a max x max bounding box, aspect
                // preserved, never crops — a single number is enough
                // (§3.2). cover(): the only cropping tier, fixed
                // dimensions, admin-grid only.
                $image = match ($config['method']) {
                    'scale' => $image->scale($config['max'], $config['max']),
                    'cover' => $image->cover($config['width'], $config['height']),
                };

                $image = $image->toWebp()->quality($config['quality']);

                $encoded = $image->toBytes();
                // Real dimensions after processing, not the configured
                // max — a source smaller than the bound never upscales
                // (§3.4), so these can legitimately differ from $config.
                $width = $image->width();
                $height = $image->height();

                $path = "{$basePath}-{$tier}.webp";
                $this->storageAdapter->storeAt($encoded, $disk, $path);
                $storedPaths[] = $path;

                $variants[] = new MediaVariant($tier, $width, $height, $config['quality'], $path);
            }

            return $variants;
        } catch (Throwable $e) {
            // §3.5: all-or-nothing. A failure on any tier deletes every
            // variant already written for this asset — no orphaned
            // files consuming storage for an asset that's unusable
            // anyway.
            foreach ($storedPaths as $path) {
                $this->storageAdapter->delete($disk, $path);
            }

            throw MediaProcessingException::variantGenerationFailed($e->getMessage());
        }
    }

    private static function pathWithoutExtension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? substr($path, 0, -(strlen($extension) + 1)) : $path;
    }
}
