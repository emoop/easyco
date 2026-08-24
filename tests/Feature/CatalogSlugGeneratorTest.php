<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests App\Providers\CatalogSlugGeneratorServiceProvider's
 * 'catalog.product.slug' listener directly, via Hook::apply() — not
 * through an HTTP route. Covers: Unicode-aware auto-generation from a
 * name (no ASCII transliteration, ever), manual-override cleanup, dedup
 * collision suffixes (including with a Cyrillic base, not just ASCII),
 * and the punctuation-only fallback to "product".
 */
class CatalogSlugGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulgarian_name_produces_a_cyrillic_slug_not_a_transliteration(): void
    {
        $slug = Hook::apply('catalog.product.slug', '', 'Червена рокля');

        $this->assertSame('червена-рокля', $slug);
        $this->assertConstructibleAsProductSlug($slug);
    }

    public function test_turkish_name_produces_a_turkish_diacritic_slug(): void
    {
        $slug = Hook::apply('catalog.product.slug', '', 'Kırmızı Elbise');

        $this->assertSame('kırmızı-elbise', $slug);
        $this->assertConstructibleAsProductSlug($slug);
    }

    public function test_russian_name_produces_a_cyrillic_slug(): void
    {
        $slug = Hook::apply('catalog.product.slug', '', 'Красное платье');

        $this->assertSame('красное-платье', $slug);
        $this->assertConstructibleAsProductSlug($slug);
    }

    public function test_english_name_with_punctuation_strips_punctuation_and_collapses_hyphens(): void
    {
        $slug = Hook::apply('catalog.product.slug', '', 'Product #1 (New!)');

        $this->assertSame('product-1-new', $slug);
        $this->assertConstructibleAsProductSlug($slug);
    }

    public function test_manual_override_is_cleaned_up_but_not_transliterated(): void
    {
        // A merchant might type this expecting it to be normalized, not
        // rejected — and it must stay Cyrillic, never become Latin.
        $slug = Hook::apply('catalog.product.slug', 'Червена Рокля', 'this name is irrelevant here');

        $this->assertSame('червена-рокля', $slug);
    }

    public function test_manual_override_with_punctuation_is_cleaned_up(): void
    {
        $slug = Hook::apply('catalog.product.slug', 'Nike / Air-Max!!', 'irrelevant');

        $this->assertSame('nike-air-max', $slug);
    }

    public function test_collision_produces_an_incrementing_ascii_suffix(): void
    {
        $this->persistProductWithSlug('nike-air-max');

        $slug = Hook::apply('catalog.product.slug', '', 'Nike Air Max');

        $this->assertSame('nike-air-max-1', $slug);
    }

    public function test_collision_produces_an_incrementing_suffix_with_a_cyrillic_base(): void
    {
        $this->persistProductWithSlug('червена-рокля');
        $this->persistProductWithSlug('червена-рокля-1');

        $slug = Hook::apply('catalog.product.slug', '', 'Червена рокля');

        $this->assertSame('червена-рокля-2', $slug);
    }

    public function test_a_punctuation_only_name_falls_back_to_product(): void
    {
        $slug = Hook::apply('catalog.product.slug', '', '!!! ??? ###');

        $this->assertSame('product', $slug);
    }

    public function test_a_punctuation_only_name_falls_back_and_still_dedups(): void
    {
        $this->persistProductWithSlug('product');

        $slug = Hook::apply('catalog.product.slug', '', '!!!');

        $this->assertSame('product-1', $slug);
    }

    /**
     * Proves the generated slug actually passes Product's own
     * assertValidSlug() — not just eyeballed as "looks right".
     */
    private function assertConstructibleAsProductSlug(string $slug): void
    {
        $product = Product::createSimple('Test Product', 'SKU-'.uniqid(), $slug);

        $this->assertSame($slug, $product->slug());
    }

    private function persistProductWithSlug(string $slug): void
    {
        $product = Product::createSimple('Existing Product', 'SKU-'.uniqid(), $slug);

        app(ProductRepository::class)->save($product);
    }
}
