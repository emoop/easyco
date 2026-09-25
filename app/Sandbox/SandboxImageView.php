<?php

namespace App\Sandbox;

/**
 * One image on the sandbox product page — url and optional alt text,
 * nothing else.
 *
 * The url is already fully built (MediaStorageAdapter::url(), i.e. the
 * Media domain's own boundary, against the asset's own disk) and the alt
 * text is the merchant's own MediaAsset::altText(), which is nullable by
 * contract — the view falls back to the product name rather than
 * rendering alt="" on a product photo, where empty alt text would tell a
 * screen reader the image is decorative when it is not.
 */
final readonly class SandboxImageView
{
    public function __construct(
        public string $url,
        public ?string $altText,
    ) {
    }
}
