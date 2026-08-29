<?php

namespace EasyCo\Media;

/**
 * One generated WebP rendition of an IMAGE/SOCIAL_IMAGE MediaAsset —
 * see media-domain-design.md §3/§8. A plain value object: tier name,
 * pixel dimensions, WebP quality, and the storage path it was written
 * to. Never constructed directly by application code — always produced
 * by the (not yet implemented) processing pipeline and handed to
 * MediaAsset::markReady().
 */
final class MediaVariant
{
    public function __construct(
        public readonly string $tier,
        public readonly int $width,
        public readonly int $height,
        public readonly int $quality,
        public readonly string $path,
    ) {
    }
}
