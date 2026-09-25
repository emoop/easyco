<?php

namespace App\Sandbox;

/**
 * The whole sandbox product page's data — prompt D, D5, assembled by
 * SandboxProductPage in one place so both Blade views and their tests
 * read the same real values (the project's own "assert real values, not
 * shape" discipline, storefront-frontend-design.md §8).
 *
 * $priceHtml is the PRODUCT-level price range through the shared display
 * rule (App\Services\ProductPriceDisplay::rangeHtml()) — the same string
 * the list shows for this product, which is what makes D5's page and D4's
 * card agree without either re-deriving anything. D5 itself lists only
 * per-variation prices; the product-level range is added here because a
 * customer-facing product page that shows no price at all above the
 * variations table would be the more surprising of the two choices, and
 * because the rule to do so already exists and is reused rather than
 * invented (see the review report's own "decisions taken" note).
 *
 * $description is nullable BY CONTRACT (Product::description() is), and
 * the view renders an explicit empty state rather than an empty <p>.
 *
 * $isSimple states which of D3's two variation branches this page is
 * rendering (SandboxCatalogReader's own docblock), so the view never has
 * to infer it from emptiness: a SIMPLE product has no selectable options,
 * so it renders $universalVariation (stock + purchasable state) under the
 * price and NO variations table, while a VARIABLE product renders
 * $variations as the table. The two are mutually exclusive by
 * construction — $variations is [] for a SIMPLE product and
 * $universalVariation is null for a VARIABLE one, and $universalVariation
 * is also null when a SIMPLE product's universal variation is not ACTIVE
 * (the view then renders neither fact rather than a misleading zero).
 */
final readonly class SandboxProductPageView
{
    /**
     * @param SandboxImageView[] $images
     * @param SandboxVariationRow[] $variations
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $brandName,
        public ?string $description,
        public string $priceHtml,
        public bool $isSimple,
        public ?SandboxUniversalVariation $universalVariation,
        public array $images,
        public array $variations,
    ) {
    }
}
