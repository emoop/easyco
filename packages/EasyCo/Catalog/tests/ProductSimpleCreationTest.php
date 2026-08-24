<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

final class ProductSimpleCreationTest extends TestCase
{
    public function test_simple_product_has_exactly_one_universal_variation(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertSame(ProductType::SIMPLE, $product->type());
        $this->assertCount(1, $product->variations());

        $universal = $product->universalVariation();
        $this->assertNotNull($universal);
        $this->assertSame(VariationType::UNIVERSAL, $universal->type());
        $this->assertSame(VariationStatus::ACTIVE, $universal->status());
    }

    public function test_universal_variation_is_never_customer_visible(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertFalse($product->universalVariation()->isVisible());
    }

    public function test_universal_variation_cannot_be_made_visible(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->expectException(\LogicException::class);
        $product->universalVariation()->setVisible(true);
    }

    public function test_universal_variation_is_purchasable_by_default(): void
    {
        // The customer never *selects* it, but it must be sellable —
        // that's the whole point of the Universal-variation model.
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertTrue($product->universalVariation()->isEffectivelyPurchasable());
    }

    public function test_variable_product_starts_with_no_variations(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');

        $this->assertSame(ProductType::VARIABLE, $product->type());
        $this->assertCount(0, $product->variations());
        $this->assertNull($product->universalVariation());
    }

    public function test_id_assignment_backfills_the_pending_universal_variation_product_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        // Before persistence, the Variation doesn't know its parent's id yet.
        $this->assertSame('', $product->universalVariation()->productId());

        $product->assignId('123');

        $this->assertSame('123', $product->universalVariation()->productId());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->assignId('123');

        $this->expectException(\LogicException::class);
        $product->assignId('456');
    }
}
