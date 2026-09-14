<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

final class ProductSeasonTest extends TestCase
{
    public function test_a_product_constructed_without_a_season_has_a_null_season_id_by_default(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->assertNull($product->seasonId());
    }

    public function test_assign_season_sets_the_season_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->assignSeason('7');

        $this->assertSame('7', $product->seasonId());
    }

    public function test_assign_season_with_null_clears_an_existing_season_id(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->assignSeason('7');

        $product->assignSeason(null);

        $this->assertNull($product->seasonId());
    }
}
