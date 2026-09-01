<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

final class ProductBrandTest extends TestCase
{
    public function test_a_product_constructed_without_a_brand_has_a_null_brand_id_by_default(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertNull($product->brandId());
    }

    public function test_assign_brand_sets_the_brand_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->assignBrand('7');

        $this->assertSame('7', $product->brandId());
    }

    public function test_assign_brand_with_null_clears_an_existing_brand_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->assignBrand('7');

        $product->assignBrand(null);

        $this->assertNull($product->brandId());
    }
}
