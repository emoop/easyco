<?php

namespace App\Storefront\ReadModels;

/**
 * One item of the product page gallery (GL-1): a usable IMAGE or a usable VIDEO, in the product's media order.
 *
 * - image   kind 'image': `image` is the big picture (medium rendition + srcset), `thumbUrl`/`thumbWidth`/`thumbHeight` the narrowest rendition,
 *           `largeUrl` the WIDEST rendition that exists (the lightbox image, never the upload), `imageIndex` its place among the images only.
 * - video   kind 'video': `videoUrl` + `videoMime` (the file's real type); videos have no renditions and no poster, and are not in the lightbox.
 * All text is RAW (the view escapes).
 */
final readonly class GalleryItem
{
    public function __construct(
        public string $kind,
        public ?int $imageIndex = null,
        public ?ImageSet $image = null,
        public ?string $thumbUrl = null,
        public ?int $thumbWidth = null,
        public ?int $thumbHeight = null,
        public ?string $largeUrl = null,
        public ?string $videoUrl = null,
        public ?string $videoMime = null,
    ) {
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }
}
