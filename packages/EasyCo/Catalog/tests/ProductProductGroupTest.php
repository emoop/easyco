<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

final class ProductProductGroupTest extends TestCase
{
    public function test_a_product_constructed_without_a_group_has_a_null_group_id_by_default(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertNull($product->productGroupId());
    }

    public function test_assign_product_group_sets_the_group_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->assignProductGroup('4');

        $this->assertSame('4', $product->productGroupId());
    }

    public function test_assign_product_group_with_null_clears_an_existing_group_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->assignProductGroup('4');

        $product->assignProductGroup(null);

        $this->assertNull($product->productGroupId());
    }
}
