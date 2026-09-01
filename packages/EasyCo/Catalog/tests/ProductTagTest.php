<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\ProductTag;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductTagTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $productTag = new ProductTag(
            id: null,
            productId: 'product-154215',
            tagId: 'tag-1',
        );

        $this->assertNull($productTag->id());
        $this->assertSame('product-154215', $productTag->productId());
        $this->assertSame('tag-1', $productTag->tagId());
    }

    public function test_empty_product_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductTag(id: null, productId: '', tagId: 'tag-1');
    }

    public function test_empty_tag_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductTag(id: null, productId: 'product-154215', tagId: '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $productTag = new ProductTag(id: null, productId: 'product-154215', tagId: 'tag-1');

        $productTag->assignId('1');
        $this->assertSame('1', $productTag->id());

        $this->expectException(LogicException::class);
        $productTag->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $productTag = ProductTag::reconstituteFromStorage(
            id: '7',
            productId: 'product-154215',
            tagId: 'tag-9',
        );

        $this->assertSame('7', $productTag->id());
        $this->assertSame('product-154215', $productTag->productId());
        $this->assertSame('tag-9', $productTag->tagId());
    }
}
