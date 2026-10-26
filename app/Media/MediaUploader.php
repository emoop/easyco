<?php

namespace App\Media;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\ImageNotCleanableException;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use finfo;
use Illuminate\Support\Facades\Image;

/**
 * Uploads from the «Медия» page (ML-1b): the SAME path as every other upload — MediaStorageAdapter::store() (the metadata strip and the refusal of a damaged picture of ML-0 / ML-1a), then a
 * MediaAsset with its details, and the processing job for an IMAGE only (media-domain-design.md §3.6: a video has no pipeline and the job would crash on it). What differs from the product forms:
 * NO minimum dimensions (those stay product-only). The kind is decided by the CONTENT (finfo), from a short list: JPEG, PNG, WebP, GIF (+ AVIF / HEIC where the server decodes them) and MP4, WebM,
 * MOV; SVG and everything else is refused. One bad file never cancels the others: it comes back in `refused` with the reason.
 */
final class MediaUploader
{
    public const MAX_FILES = 20;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/heic', 'image/heif'];

    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public function __construct(
        private readonly MediaAssetRepository $assets,
        private readonly MediaStorageAdapter $storage,
        private readonly MediaImageProcessor $processor,
    ) {
    }

    /** The mimes the upload field offers (the server checks the content again). @return list<string> */
    public static function acceptedMimes(): array
    {
        return [...self::IMAGE_MIMES, ...self::VIDEO_MIMES];
    }

    /**
     * @param  iterable<array{name: string, content: string}>  $files
     * @param  bool  $imagesOnly  a picture field (the media picker): a video is refused with its reason
     * @param  bool  $videosOnly  ML-3, the product video field: a picture is refused with its reason
     * @param  list<string>|null  $mimes  LG-1, the logo: only these MIME types (found by the CONTENT, never the file name); any other is refused BEFORE it is stored
     * @param  int|null  $minWidth  ML-3, product photos: a smaller picture (after it is turned upright, as the API does) is refused BEFORE anything is stored
     * @return array{stored: list<int>, refused: list<array{name: string, reason: string}>}
     */
    public function upload(iterable $files, bool $imagesOnly = false, bool $videosOnly = false, ?int $minWidth = null, ?int $minHeight = null, ?array $mimes = null): array
    {
        $stored = [];
        $refused = [];

        foreach ($files as $file) {
            $name = $file['name'];
            $content = $file['content'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
            $isImage = in_array($mime, self::IMAGE_MIMES, true);
            $isVideo = in_array($mime, self::VIDEO_MIMES, true);

            if ($isVideo && $imagesOnly) {
                $refused[] = ['name' => $name, 'reason' => __('media.picker.images_only')];

                continue;
            }

            if ($mimes !== null && ! in_array($mime, $mimes, true)) {
                $refused[] = ['name' => $name, 'reason' => __('media.picker.mime_only', ['types' => self::typeNames($mimes)])];

                continue;
            }

            if ($isImage && $videosOnly) {
                $refused[] = ['name' => $name, 'reason' => __('media.picker.videos_only')];

                continue;
            }

            if (! $isImage && ! $isVideo) {
                $refused[] = ['name' => $name, 'reason' => __('media.library.upload.unsupported')];

                continue;
            }

            $limitKb = $isImage ? (int) config('services.media.max_image_size_kb', 10240) : (int) config('services.media.max_video_size_kb', 204800);

            if (strlen($content) > $limitKb * 1024) {
                $refused[] = ['name' => $name, 'reason' => __('media.library.upload.too_large')];

                continue;
            }

            if ($isImage && ! $this->processor->supportsFormat($content)) {
                $refused[] = ['name' => $name, 'reason' => ImageNotCleanableException::unsupportedFormat()->getMessage()];

                continue;
            }

            if ($isImage && ($minWidth !== null || $minHeight !== null)) {
                $tooSmall = $this->tooSmall($content, (int) $minWidth, (int) $minHeight);

                if ($tooSmall !== null) {
                    $refused[] = ['name' => $name, 'reason' => $tooSmall];

                    continue;
                }
            }

            try {
                $file = $this->storage->store($content, $name);
            } catch (ImageNotCleanableException $e) {
                $refused[] = ['name' => $name, 'reason' => $e->getMessage()];

                continue;
            }

            $asset = MediaAsset::create($isImage ? MediaType::IMAGE : MediaType::VIDEO, $file->disk, $file->path, null, $file->details);
            $this->assets->save($asset);
            $isImage && ProcessMediaAssetJob::dispatch($asset->id());
            $stored[] = (int) $asset->id();
        }

        return ['stored' => $stored, 'refused' => $refused];
    }

    /** «PNG и WebP»: the names of MIME types for a message. @param  list<string>  $mimes */
    public static function typeNames(array $mimes): string
    {
        $names = ['image/png' => 'PNG', 'image/webp' => 'WebP', 'image/jpeg' => 'JPEG', 'image/gif' => 'GIF', 'image/avif' => 'AVIF', 'image/heic' => 'HEIC'];

        return implode(' '.__('media.picker.and').' ', array_map(static fn (string $mime): string => $names[$mime] ?? $mime, $mimes));
    }

    /** The reason a picture is below the minimum size, else null. The size is read the way the API reads it: decoded and turned upright (a phone photo's EXIF orientation swaps width and height). */
    private function tooSmall(string $content, int $minWidth, int $minHeight): ?string
    {
        $oriented = Image::fromBytes($content)->orient();

        if ($oriented->width() >= $minWidth && $oriented->height() >= $minHeight) {
            return null;
        }

        return __('media.picker.errors.too_small', ['w' => $oriented->width(), 'h' => $oriented->height(), 'min_w' => $minWidth, 'min_h' => $minHeight]);
    }
}
