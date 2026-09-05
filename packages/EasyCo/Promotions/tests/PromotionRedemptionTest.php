<?php

namespace EasyCo\Promotions\Tests;

use DateTimeImmutable;
use EasyCo\Promotions\PromotionRedemption;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PromotionRedemptionTest extends TestCase
{
    private function redeemedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $redeemedAt = $this->redeemedAt();

        $redemption = new PromotionRedemption(
            id: null,
            promotionId: '7',
            orderId: '42',
            accountId: '9',
            redeemedAt: $redeemedAt,
        );

        $this->assertNull($redemption->id());
        $this->assertSame('7', $redemption->promotionId());
        $this->assertSame('42', $redemption->orderId());
        $this->assertSame('9', $redemption->accountId());
        $this->assertSame($redeemedAt, $redemption->redeemedAt());
    }

    public function test_null_account_id_succeeds_for_a_guest_redemption(): void
    {
        $redemption = new PromotionRedemption(
            id: null,
            promotionId: '7',
            orderId: '42',
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );

        $this->assertNull($redemption->accountId());
    }

    public function test_empty_string_account_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromotionRedemption(
            id: null,
            promotionId: '7',
            orderId: '42',
            accountId: '',
            redeemedAt: $this->redeemedAt(),
        );
    }

    public function test_empty_promotion_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromotionRedemption(
            id: null,
            promotionId: '',
            orderId: '42',
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );
    }

    public function test_empty_order_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromotionRedemption(
            id: null,
            promotionId: '7',
            orderId: '',
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $redemption = new PromotionRedemption(
            id: null,
            promotionId: '7',
            orderId: '42',
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );

        $redemption->assignId('1');
        $this->assertSame('1', $redemption->id());

        $this->expectException(LogicException::class);
        $redemption->assignId('2');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $redeemedAt = $this->redeemedAt();

        $redemption = PromotionRedemption::reconstituteFromStorage(
            id: '3',
            promotionId: '7',
            orderId: '42',
            accountId: '9',
            redeemedAt: $redeemedAt,
        );

        $this->assertSame('3', $redemption->id());
        $this->assertSame('7', $redemption->promotionId());
        $this->assertSame('42', $redemption->orderId());
        $this->assertSame('9', $redemption->accountId());
        $this->assertSame($redeemedAt, $redemption->redeemedAt());
    }

    public function test_reconstitute_from_storage_round_trips_a_guest_redemption(): void
    {
        $redemption = PromotionRedemption::reconstituteFromStorage(
            id: '4',
            promotionId: '7',
            orderId: '42',
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );

        $this->assertNull($redemption->accountId());
    }
}
