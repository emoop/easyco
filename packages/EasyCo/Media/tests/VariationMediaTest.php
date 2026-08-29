<?php

namespace EasyCo\Media\Tests;

use EasyCo\Media\VariationMedia;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class VariationMediaTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $variationMedia = new VariationMedia(
            id: null,
            variationId: 'variation-154215-1',
            mediaId: 'media-1',
            sortOrder: 3,
        );

        $this->assertNull($variationMedia->id());
        $this->assertSame('variation-154215-1', $variationMedia->variationId());
        $this->assertSame('media-1', $variationMedia->mediaId());
        $this->assertSame(3, $variationMedia->sortOrder());
    }

    public function test_sort_order_defaults_to_zero_when_not_passed(): void
    {
        $variationMedia = new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: 'media-1');

        $this->assertSame(0, $variationMedia->sortOrder());
    }

    public function test_empty_variation_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VariationMedia(id: null, variationId: '', mediaId: 'media-1');
    }

    public function test_empty_media_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: '');
    }

    public function test_negative_sort_order_throws_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: 'media-1', sortOrder: -1);
    }

    public function test_negative_sort_order_throws_on_update(): void
    {
        $variationMedia = new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: 'media-1');

        $this->expectException(InvalidArgumentException::class);
        $variationMedia->updateSortOrder(-1);
    }

    public function test_update_sort_order_to_a_valid_value_succeeds(): void
    {
        $variationMedia = new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: 'media-1');

        $variationMedia->updateSortOrder(5);

        $this->assertSame(5, $variationMedia->sortOrder());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $variationMedia = new VariationMedia(id: null, variationId: 'variation-154215-1', mediaId: 'media-1');

        $variationMedia->assignId('1');
        $this->assertSame('1', $variationMedia->id());

        $this->expectException(LogicException::class);
        $variationMedia->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $variationMedia = VariationMedia::reconstituteFromStorage(
            id: '7',
            variationId: 'variation-154215-1',
            mediaId: 'media-9',
            sortOrder: 2,
        );

        $this->assertSame('7', $variationMedia->id());
        $this->assertSame('variation-154215-1', $variationMedia->variationId());
        $this->assertSame('media-9', $variationMedia->mediaId());
        $this->assertSame(2, $variationMedia->sortOrder());
    }
}
