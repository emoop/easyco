<?php

namespace App\Mail;

use App\Storefront\Cart\CartLineEnricher;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * MAIL-2 (site-logo.md, «Снимки на продуктите в имейла»): the small product pictures of the order-confirmation mail.
 *
 *  - RASTER ONLY, JPEG: the media library makes WebP variants, and Outlook desktop and other clients do not show WebP. So the mail has its own JPEG, made with GD from the stored ORIGINAL (no queue, no
 *    Imagick, no process). Transparency is flattened onto white, quality 82, never upscaled, the proportions kept (no crop).
 *  - SIZE: the file is at most 144 x 192 px; the mail SHOWS it at half of that (72 x 96, 2x for sharp screens).
 *  - LAZY, ONCE, KEPT: the first mail that needs a picture writes `storage/app/public/mail-thumbs/{asset id}-{12 hex}.jpg`; later mails reuse it. The 12 hex are a hash of what identifies the original
 *    (the asset id, its path, size, type and dimensions, and this generator's version), so a replaced original, or a new rule, gives a NEW file. Files are never pruned by age: a customer opens an old
 *    mail for years. `media:sweep-files` only looks in `uploads/`, and a deleted asset's files are removed by path (DeleteMediaFilesJob), so a thumbnail stays and old mails keep their picture.
 *  - NEVER STOPS A MAIL: every failure (no picture, file missing, unreadable, too big, GD without the type, no write) is caught, ONE warning with the product id is logged, and the row is printed without a picture.
 *  - NO TRACKING: a first-party file on the shop's own domain, absolute URL, no query string.
 *
 * Runs only when the mail is BUILT (OrderConfirmationContent, inside the queued SendMailJob), never in the checkout request.
 */
class MailThumbnails
{
    public const DIRECTORY = 'mail-thumbs';

    /** The file's longest sides (2x what the mail shows). */
    public const FILE_MAX_WIDTH = 144;

    public const FILE_MAX_HEIGHT = 192;

    public const QUALITY = 82;

    /** Part of the file name hash: a change of size or quality gives new files. */
    public const VERSION = 'v1';

    /** Refused before decoding: GD holds 4 bytes per pixel (16 MP = 64 MB). */
    public const MAX_PIXELS = 16_000_000;

    public const MAX_BYTES = 25_000_000;

    private const FILE = '#^\d+-[0-9a-f]{12}\.jpg$#';

    public function __construct(
        private readonly CartLineEnricher $images,
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    /**
     * The thumbnail of each variation that has a picture and could be made. Never throws.
     *
     * @param  list<string>  $variationIds
     * @return array<string, array{url: string, width: int, height: int}> variation id => the picture as the mail shows it (width and height are the SHOWN size)
     */
    public function forVariations(array $variationIds): array
    {
        $origin = rtrim((string) config('app.url'), '/');

        if ($variationIds === [] || preg_match('#^https?://#i', $origin) !== 1) {
            return [];
        }

        try {
            $found = $this->images->imageAssets($variationIds);
        } catch (Throwable $e) {
            Log::warning('mail.thumbnail_lookup_failed', ['exception' => $e::class]);

            return [];
        }

        $result = [];
        $made = [];

        foreach ($found['assets'] as $variationId => $asset) {
            $productId = $found['products'][$variationId] ?? null;

            // a picture shared by several lines (or a product with several variations) is made once per mail
            $made[(int) $asset->getKey()] ??= $this->thumbnail($asset, $productId);

            if ($made[(int) $asset->getKey()] !== null) {
                $result[(string) $variationId] = ['url' => $origin.'/storage/'.self::DIRECTORY.'/'.$made[(int) $asset->getKey()]['name']] + $made[(int) $asset->getKey()]['shown'];
            }
        }

        return $result;
    }

    /** @return array{name: string, shown: array{width: int, height: int}}|null */
    private function thumbnail(MediaAssetModel $asset, ?string $productId): ?array
    {
        try {
            $name = $asset->getKey().'-'.substr(sha1(implode('|', [self::VERSION, $asset->getKey(), $asset->disk, $asset->path, $asset->size_bytes, $asset->mime, $asset->width, $asset->height])), 0, 12).'.jpg';
            $path = self::DIRECTORY.'/'.$name;
            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                $size = @getimagesizefromstring($disk->get($path));

                if ($size !== false && $size[2] === IMAGETYPE_JPEG) {
                    return ['name' => $name, 'shown' => self::shown((int) $size[0], (int) $size[1])];
                }
                // a damaged file is made again below
            }

            $made = $this->generate($this->storage->get((string) $asset->disk, (string) $asset->path));

            if (! $disk->put($path, $made['bytes'])) {
                throw new RuntimeException('The thumbnail could not be written.');
            }

            return ['name' => $name, 'shown' => self::shown($made['width'], $made['height'])];
        } catch (Throwable $e) {
            Log::warning('mail.thumbnail_failed', ['product_id' => $productId, 'asset_id' => $asset->getKey(), 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * The JPEG of an original: at most 144 x 192, never upscaled, proportions kept, flattened onto white.
     *
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws RuntimeException
     */
    public function generate(string $source): array
    {
        if (! function_exists('imagetypes') || (imagetypes() & IMG_JPG) === 0) {
            throw new RuntimeException('GD cannot write JPEG.');
        }

        // the HEADER is read, nothing is decoded yet: the content decides the type and the size
        $info = strlen($source) > self::MAX_BYTES ? false : @getimagesizefromstring($source);
        $types = [IMAGETYPE_JPEG => IMG_JPG, IMAGETYPE_PNG => IMG_PNG, IMAGETYPE_GIF => IMG_GIF, IMAGETYPE_WEBP => IMG_WEBP];

        if ($info === false || ! isset($types[$info[2]]) || (imagetypes() & $types[$info[2]]) === 0 || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('The picture cannot be read.');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('The picture is too big.');
        }

        $image = @imagecreatefromstring($source);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The picture cannot be read.');
        }

        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1.0, self::FILE_MAX_WIDTH / $w, self::FILE_MAX_HEIGHT / $h);
        $width = max(1, (int) round($w * $scale));
        $height = max(1, (int) round($h * $scale));

        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            throw new RuntimeException('The picture cannot be resized.');
        }

        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagealphablending($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, $w, $h);

        ob_start();
        $ok = imagejpeg($target, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $ok || $bytes === '') {
            throw new RuntimeException('The picture cannot be saved.');
        }

        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    /** What the mail shows: half the file (2x), at most 72 x 96. */
    public static function shown(int $fileWidth, int $fileHeight): array
    {
        return ['width' => max(1, (int) round($fileWidth / 2)), 'height' => max(1, (int) round($fileHeight / 2))];
    }

    /** Whether a file name is one of ours (for tests and any future tool). */
    public static function isOurs(string $name): bool
    {
        return preg_match(self::FILE, $name) === 1;
    }
}
