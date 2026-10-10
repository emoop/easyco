<?php

namespace App\Storefront\ReadModels;

/**
 * One image with its renditions (storefront-design.md §4, basic form; S5 refines it).
 *
 * - srcset     the READY variant rows of the storefront tiers, by their REAL width (never upscaled), narrowest first.
 * - src        the default rendition: `medium` when present, else the largest ready variant.
 * - width/height  of the `src` rendition, taken from its variant row.
 * - alt        plain text (raw, NOT escaped: escaping is the view's job).
 */
final readonly class ImageSet
{
    /**
     * @param list<array{url: string, width: int}> $srcset
     */
    public function __construct(
        public string $src,
        public string $alt,
        public int $width,
        public int $height,
        public array $srcset,
        public string $sizesHint,
    ) {
    }

    /** @return array{src: string, alt: string, width: int, height: int, srcset: list<array{url: string, width: int}>, sizes_hint: string} */
    public function toArray(): array
    {
        return [
            'src' => $this->src,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'srcset' => $this->srcset,
            'sizes_hint' => $this->sizesHint,
        ];
    }
}
