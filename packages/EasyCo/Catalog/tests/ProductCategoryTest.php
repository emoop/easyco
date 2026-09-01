<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\ProductCategory;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductCategoryTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $productCategory = new ProductCategory(
            id: null,
            productId: 'product-154215',
            categoryId: 'category-1',
        );

        $this->assertNull($productCategory->id());
        $this->assertSame('product-154215', $productCategory->productId());
        $this->assertSame('category-1', $productCategory->categoryId());
    }

    public function test_empty_product_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductCategory(id: null, productId: '', categoryId: 'category-1');
    }

    public function test_empty_category_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductCategory(id: null, productId: 'product-154215', categoryId: '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $productCategory = new ProductCategory(id: null, productId: 'product-154215', categoryId: 'category-1');

        $productCategory->assignId('1');
        $this->assertSame('1', $productCategory->id());

        $this->expectException(LogicException::class);
        $productCategory->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $productCategory = ProductCategory::reconstituteFromStorage(
            id: '7',
            productId: 'product-154215',
            categoryId: 'category-9',
        );

        $this->assertSame('7', $productCategory->id());
        $this->assertSame('product-154215', $productCategory->productId());
        $this->assertSame('category-9', $productCategory->categoryId());
    }
}
