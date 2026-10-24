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
     * @return array{stored: list<int>, refused: list<array{name: string, reason: string}>}
     */
    public function upload(iterable $files): array
    {
        $stored = [];
        $refused = [];

        foreach ($files as $file) {
            $name = $file['name'];
            $content = $file['content'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
            $isImage = in_array($mime, self::IMAGE_MIMES, true);
            $isVideo = in_array($mime, self::VIDEO_MIMES, true);

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
}
