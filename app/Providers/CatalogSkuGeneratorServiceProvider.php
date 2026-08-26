<?php

namespace App\Providers;

use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the real SKU-generation listeners on the
 * 'catalog.product.base_sku' and 'catalog.variation.sku' filters — see
 * extensibility-design-and-hooks.md's Hook Reference. Replaces two
 * temporary placeholders that predate this mechanism being real:
 * DemoHooksServiceProvider's proof-of-concept base_sku counter (deleted
 * — that mechanism is now proven by two real generators, not a demo),
 * and VariationCombinationGenerator's previously-required
 * $skuForCombination callable (now optional — see that class's
 * docblock for why it still cannot call Hook:: itself).
 *
 * Structurally the same shape as
 * App\Providers\CatalogSlugGeneratorServiceProvider: registered in
 * boot(), not register(), for the same reason — EasyCo\Extensibility\
 * HookRegistry's singleton binding must already exist before anything
 * resolves it, which Laravel only guarantees once every provider's
 * register() phase has finished.
 *
 * THE SEQUENCE ITSELF IS NOT OWNED HERE: catalog_sku_sequence and its
 * concurrency-safe read+increment live inside the Catalog package
 * (EasyCo\Catalog\Contracts\SkuSequenceRepository /
 * Persistence\Eloquent\EloquentSkuSequenceRepository) — this class only
 * resolves that contract from the container and calls it. Mirrors the
 * EasyCo\Pricing\DefaultCurrency split exactly: the state-holder lives
 * inside the owning domain package, only the Laravel-specific wiring
 * (registering the Hook listener itself, reading
 * config('services.catalog.base_sku_sequence_start') at migration time)
 * lives in app/. An earlier version of this migration lived directly in
 * the root app's database/migrations/ and this class touched
 * catalog_sku_sequence via raw DB:: calls — both were corrected to match
 * this boundary; see the migration's own docblock.
 */
class CatalogSkuGeneratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Hook::filter('catalog.product.base_sku', function (string $baseSku): string {
            if ($baseSku !== '') {
                // A merchant-supplied base_sku is an opaque identifier,
                // not a URL-safe token — returned completely unchanged,
                // unlike catalog.product.slug, which cleans up even a
                // manual override. There is no "correct" normalized form
                // of an arbitrary SKU string to normalize toward.
                return $baseSku;
            }

            return (string) app(SkuSequenceRepository::class)->next();
        });

        // {baseSku}-{n}, a simple per-product sequential integer starting
        // at 1 — deliberately NOT attribute-value-based (e.g. NOT
        // "154215-s-black"). Decided explicitly with the domain owner:
        // attribute values may be Cyrillic, may be long ("11-12 years
        // (152cm)"), and the axis count varies per product, all of which
        // make value-derived SKUs long and awkward to type at a POS
        // terminal. base_sku is the human-typed lookup key; individual
        // variations are normally scanned by barcode instead, so the
        // variation sku only needs to be short and unique, not
        // descriptive. See catalog-domain-design.md §3.2 for the same
        // reasoning recorded in the domain design.
        Hook::filter('catalog.variation.sku', function (string $value, string $baseSku, Product $product): string {
            if ($value !== '') {
                return $value;
            }

            // Best-effort candidate only, not the authoritative
            // uniqueness guarantee — see
            // EloquentProductRepository::saveVariationModelWithSkuCollisionRetry()
            // for the real, DB-constraint-driven safety net.
            $n = count($product->variations()) + 1;

            return "{$baseSku}-{$n}";
        });
    }

    /**
     * Returns a closure ready to pass as
     * VariationCombinationGenerator::generate()'s $skuForCombination
     * argument, wired to the real 'catalog.variation.sku' Hook filter
     * for the given Product.
     *
     * WHY THIS EXISTS: VariationCombinationGenerator cannot call
     * Hook::apply() itself — it's a Catalog domain class, and this
     * project's hard architectural rule
     * (extensibility-design-and-hooks.md §2 / CLAUDE.md) forbids domain
     * packages from touching EasyCo\Extensibility directly. So every
     * caller that wants the real hook-based generation has to build
     * this exact closure — `fn (array $combination) =>
     * Hook::apply('catalog.variation.sku', '', $product->baseSku(), $product)`
     * — themselves. Without one canonical place to get it from, that
     * wiring (and the 'catalog.variation.sku' hook name string itself)
     * would end up duplicated at every call site, with real risk of
     * drifting out of sync if the hook is ever renamed or its signature
     * changes. This method is that one canonical place — colocated
     * directly with the listener it wraps (this same file/class), rather
     * than a separate app/Support/ class, specifically so the hook name
     * string is never duplicated across two files.
     *
     * Does NOT change VariationCombinationGenerator itself: it stays
     * completely Hook-free, and still throws a LogicException if nothing
     * is passed for $skuForCombination — that safety net is unaffected
     * by this convenience existing.
     *
     * @return callable(array<int|string, int|string>): string
     */
    public static function variationSkuStrategy(Product $product): callable
    {
        $baseSku = $product->baseSku();

        return static function (array $combination) use ($baseSku, $product): string {
            return Hook::apply('catalog.variation.sku', '', $baseSku, $product);
        };
    }
}
