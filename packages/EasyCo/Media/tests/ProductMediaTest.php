<?php

namespace EasyCo\Media\Tests;

use EasyCo\Media\ProductMedia;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductMediaTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $productMedia = new ProductMedia(
            id: null,
            productId: 'product-154215',
            mediaId: 'media-1',
            sortOrder: 3,
        );

        $this->assertNull($productMedia->id());
        $this->assertSame('product-154215', $productMedia->productId());
        $this->assertSame('media-1', $productMedia->mediaId());
        $this->assertSame(3, $productMedia->sortOrder());
    }

    public function test_sort_order_defaults_to_zero_when_not_passed(): void
    {
        $productMedia = new ProductMedia(id: null, productId: 'product-154215', mediaId: 'media-1');

        $this->assertSame(0, $productMedia->sortOrder());
    }

    public function test_empty_product_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductMedia(id: null, productId: '', mediaId: 'media-1');
    }

    public function test_empty_media_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductMedia(id: null, productId: 'product-154215', mediaId: '');
    }

    public function test_negative_sort_order_throws_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductMedia(id: null, productId: 'product-154215', mediaId: 'media-1', sortOrder: -1);
    }

    public function test_negative_sort_order_throws_on_update(): void
    {
        $productMedia = new ProductMedia(id: null, productId: 'product-154215', mediaId: 'media-1');

        $this->expectException(InvalidArgumentException::class);
        $productMedia->updateSortOrder(-1);
    }

    public function test_update_sort_order_to_a_valid_value_succeeds(): void
    {
        $productMedia = new ProductMedia(id: null, productId: 'product-154215', mediaId: 'media-1');

        $productMedia->updateSortOrder(5);

        $this->assertSame(5, $productMedia->sortOrder());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $productMedia = new ProductMedia(id: null, productId: 'product-154215', mediaId: 'media-1');

        $productMedia->assignId('1');
        $this->assertSame('1', $productMedia->id());

        $this->expectException(LogicException::class);
        $productMedia->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $productMedia = ProductMedia::reconstituteFromStorage(
            id: '7',
            productId: 'product-154215',
            mediaId: 'media-9',
            sortOrder: 2,
        );

        $this->assertSame('7', $productMedia->id());
        $this->assertSame('product-154215', $productMedia->productId());
        $this->assertSame('media-9', $productMedia->mediaId());
        $this->assertSame(2, $productMedia->sortOrder());
    }
}
