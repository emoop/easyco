<?php

namespace App\Sandbox;

/**
 * One product tile on the sandbox list page — a plain, inert carrier for
 * already-resolved values (D4's own list: id/url, name, brand, first
 * image, price html).
 *
 * A value object rather than an array or a raw ProductModel, for the same
 * reason PriceRange/OrderAdminOrderView exist in this codebase: the
 * Blade view must not be able to reach back into the database, or
 * re-derive a price, or lazy-load a relation and silently reintroduce a
 * per-row query the whole design exists to prevent. Everything a view
 * needs is already a string on this object.
 *
 * $priceHtml is ALREADY HTML (ProductPriceDisplay's own `'<s>12.00 €</s>
 * 10.00 €'` / `'от 10.00 €'` output) and is deliberately not escaped
 * again by the view — see that class's docblock. $thumbnailUrl is null
 * for a product with no ready image (the view renders a placeholder
 * block, never a broken <img>).
 */
final readonly class SandboxProductCard
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $brandName,
        public ?string $thumbnailUrl,
        public string $priceHtml,
        public string $url,
    ) {
    }
}
