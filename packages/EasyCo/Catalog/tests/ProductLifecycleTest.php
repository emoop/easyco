<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
use EasyCo\Catalog\Product;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::rename()/publish()/markAsDraft()/archive()/
 * description()/changeDescription()/changeBaseSku() — the plain
 * mutators added alongside the existing changeSlug()/assignBrand()/
 * assignSeason() family. Mirrors ProductSlugTest's style.
 */
final class ProductLifecycleTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_rename_changes_the_name(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->rename('Nike Air Max 2026');

        $this->assertSame('Nike Air Max 2026', $product->name());
    }

    public function test_rename_reuses_the_constructors_own_validation(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->expectException(InvalidArgumentException::class);
        $product->rename('');
    }

    public function test_publish_on_a_simple_product_always_succeeds(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->publish();

        $this->assertSame(ProductStatus::ACTIVE, $product->status());
    }

    public function test_publish_on_a_variable_product_with_a_non_archived_standard_variation_succeeds(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $product->publish();

        $this->assertSame(ProductStatus::ACTIVE, $product->status());
    }

    public function test_publish_on_a_variable_product_with_zero_variations_throws(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');

        $this->expectException(CannotPublishEmptyVariableProductException::class);
        $product->publish();
    }

    public function test_publish_on_a_variable_product_with_only_archived_variations_throws(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->archive();

        $this->expectException(CannotPublishEmptyVariableProductException::class);
        $product->publish();
    }

    public function test_mark_as_draft_succeeds_from_any_starting_status(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->publish();

        $product->markAsDraft();
        $this->assertSame(ProductStatus::DRAFT, $product->status());

        // idempotent from its own target status
        $product->markAsDraft();
        $this->assertSame(ProductStatus::DRAFT, $product->status());
    }

    public function test_archive_succeeds_from_any_starting_status(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->archive();
        $this->assertSame(ProductStatus::ARCHIVED, $product->status());

        // idempotent from its own target status
        $product->archive();
        $this->assertSame(ProductStatus::ARCHIVED, $product->status());
    }

    public function test_description_defaults_to_null_on_a_freshly_created_product(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertNull($product->description());
    }

    public function test_change_description_accepts_a_real_string(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->changeDescription('A classic silhouette.');

        $this->assertSame('A classic silhouette.', $product->description());
    }

    public function test_change_description_accepts_null_to_clear_it(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->changeDescription('A classic silhouette.');

        $product->changeDescription(null);

        $this->assertNull($product->description());
    }

    public function test_change_base_sku_changes_it(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->changeBaseSku('SKU-2026');

        $this->assertSame('SKU-2026', $product->baseSku());
    }

    public function test_change_base_sku_reuses_the_constructors_own_validation(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->expectException(InvalidArgumentException::class);
        $product->changeBaseSku('');
    }
}
