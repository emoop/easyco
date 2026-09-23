<?php

namespace EasyCo\Catalog\Tests;

use DateTimeImmutable;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Product;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Product::timelineAt()/isPromoted()/promote()/unpromote() — see catalog-domain-design.md's "Product timeline" section. */
final class ProductTimelineTest extends TestCase
{
    public function test_a_new_product_starts_with_timeline_at_equal_to_created_at_and_is_not_promoted(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');

        $this->assertEquals($product->createdAt(), $product->timelineAt());
        $this->assertFalse($product->isPromoted());
    }

    public function test_a_variable_product_also_starts_un_promoted(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');

        $this->assertEquals($product->createdAt(), $product->timelineAt());
        $this->assertFalse($product->isPromoted());
    }

    public function test_promote_sets_timeline_at_to_the_given_instant_and_marks_it_promoted(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $promotedAt = $product->createdAt()->modify('+1 day');

        $product->promote($promotedAt);

        $this->assertEquals($promotedAt, $product->timelineAt());
        $this->assertTrue($product->isPromoted());
    }

    public function test_promoting_to_an_instant_before_created_at_throws(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $before = $product->createdAt()->modify('-1 day');

        $this->expectException(InvalidArgumentException::class);

        $product->promote($before);
    }

    public function test_promoting_to_exactly_created_at_is_allowed_and_is_not_less_than(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');

        // $at < createdAt is the only refused case — $at === createdAt
        // is explicitly allowed (a no-op position-wise).
        $product->promote($product->createdAt());

        $this->assertEquals($product->createdAt(), $product->timelineAt());
        $this->assertFalse($product->isPromoted(), 'a promotion to the exact same instant as createdAt is not ">" createdAt, so isPromoted() is false — the documented edge case');
    }

    public function test_re_promoting_moves_the_timeline_position_again(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $firstPromotion = $product->createdAt()->modify('+1 day');
        $secondPromotion = $product->createdAt()->modify('+2 days');

        $product->promote($firstPromotion);
        $product->promote($secondPromotion);

        $this->assertEquals($secondPromotion, $product->timelineAt());
    }

    public function test_unpromote_restores_timeline_at_to_created_at(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->promote($product->createdAt()->modify('+1 day'));

        $product->unpromote();

        $this->assertEquals($product->createdAt(), $product->timelineAt());
        $this->assertFalse($product->isPromoted());
    }

    public function test_unpromote_on_an_already_un_promoted_product_is_a_harmless_no_op(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');

        $product->unpromote();

        $this->assertEquals($product->createdAt(), $product->timelineAt());
        $this->assertFalse($product->isPromoted());
    }

    public function test_reconstitution_from_storage_takes_created_at_and_timeline_at_explicitly(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01 10:00:00');
        $timelineAt = new DateTimeImmutable('2026-01-05 10:00:00');

        $product = Product::reconstituteFromStorage(
            id: '1',
            name: 'Air Max',
            type: ProductType::SIMPLE,
            baseSku: 'SKU-1',
            slug: 'air-max',
            status: ProductStatus::DRAFT,
            catalogVisibility: CatalogVisibility::HIDDEN,
            createdAt: $createdAt,
            timelineAt: $timelineAt,
        );

        $this->assertEquals($createdAt, $product->createdAt());
        $this->assertEquals($timelineAt, $product->timelineAt());
        $this->assertTrue($product->isPromoted());
    }
}
