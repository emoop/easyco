<?php

namespace EasyCo\Promotions\Tests;

use DateTimeImmutable;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionStatus;
use EasyCo\Promotions\Promotion;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PromotionTest extends TestCase
{
    // --- create() / code normalization -----------------------------------

    public function test_create_produces_an_active_promotion(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
        );

        $this->assertNull($promotion->id());
        $this->assertSame(PromotionDiscountType::PERCENTAGE, $promotion->discountType());
        $this->assertSame(PromotionStatus::ACTIVE, $promotion->status());
        $this->assertTrue($promotion->isActive());
    }

    public function test_code_is_normalized_to_lowercase(): void
    {
        $promotion = Promotion::create(
            '  SUMMER20  ',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
        );

        $this->assertSame('summer20', $promotion->code());
    }

    public function test_empty_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);
    }

    public function test_whitespace_only_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('   ', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);
    }

    // --- discountType / percentageBasisPoints / discountAmount consistency ---

    public function test_percentage_requires_percentage_basis_points(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE);
    }

    public function test_percentage_rejects_a_negative_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: -1);
    }

    public function test_percentage_accepts_a_valid_percentage(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
        );

        $this->assertSame(2000, $promotion->percentageBasisPoints());
    }

    public function test_percentage_rejects_a_percentage_over_100_percent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 15000);
    }

    public function test_percentage_accepts_exactly_100_percent(): void
    {
        $promotion = Promotion::create(
            'FREEBIE',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 10000,
        );

        $this->assertSame(10000, $promotion->percentageBasisPoints());
    }

    public function test_percentage_accepts_exactly_zero_percent(): void
    {
        $promotion = Promotion::create(
            'NOOP',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 0,
        );

        $this->assertSame(0, $promotion->percentageBasisPoints());
    }

    public function test_percentage_rejects_a_discount_amount_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            discountAmount: Money::fromDecimal('10.00', 'EUR'),
        );
    }

    public function test_fixed_amount_requires_a_discount_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create('TENOFF', PromotionDiscountType::FIXED_AMOUNT);
    }

    public function test_fixed_amount_rejects_a_percentage_basis_points_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'TENOFF',
            PromotionDiscountType::FIXED_AMOUNT,
            percentageBasisPoints: 2000,
            discountAmount: Money::fromDecimal('10.00', 'EUR'),
        );
    }

    public function test_fixed_amount_with_a_discount_amount_succeeds(): void
    {
        $amount = Money::fromDecimal('10.00', 'EUR');

        $promotion = Promotion::create(
            'TENOFF',
            PromotionDiscountType::FIXED_AMOUNT,
            discountAmount: $amount,
        );

        $this->assertSame($amount, $promotion->discountAmount());
        $this->assertNull($promotion->percentageBasisPoints());
    }

    // --- minimumSpend / maximumSpend ---------------------------------------

    public function test_maximum_spend_equal_to_minimum_spend_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            minimumSpend: Money::fromDecimal('50.00', 'EUR'),
            maximumSpend: Money::fromDecimal('50.00', 'EUR'),
        );
    }

    public function test_maximum_spend_below_minimum_spend_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            minimumSpend: Money::fromDecimal('100.00', 'EUR'),
            maximumSpend: Money::fromDecimal('50.00', 'EUR'),
        );
    }

    public function test_maximum_spend_above_minimum_spend_succeeds(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            minimumSpend: Money::fromDecimal('50.00', 'EUR'),
            maximumSpend: Money::fromDecimal('100.00', 'EUR'),
        );

        $this->assertTrue($promotion->minimumSpend()->equals(Money::fromDecimal('50.00', 'EUR')));
        $this->assertTrue($promotion->maximumSpend()->equals(Money::fromDecimal('100.00', 'EUR')));
    }

    public function test_only_minimum_spend_set_does_not_throw(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            minimumSpend: Money::fromDecimal('50.00', 'EUR'),
        );

        $this->assertNotNull($promotion->minimumSpend());
        $this->assertNull($promotion->maximumSpend());
    }

    public function test_only_maximum_spend_set_does_not_throw(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            maximumSpend: Money::fromDecimal('100.00', 'EUR'),
        );

        $this->assertNull($promotion->minimumSpend());
        $this->assertNotNull($promotion->maximumSpend());
    }

    public function test_both_minimum_and_maximum_spend_null_does_not_throw(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
        );

        $this->assertNull($promotion->minimumSpend());
        $this->assertNull($promotion->maximumSpend());
    }

    // --- validFrom / validUntil (construction-time ordering) --------------

    public function test_valid_until_equal_to_valid_from_throws(): void
    {
        $at = new DateTimeImmutable('2026-01-01');

        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validFrom: $at,
            validUntil: $at,
        );
    }

    public function test_valid_until_before_valid_from_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: new DateTimeImmutable('2025-12-01'),
        );
    }

    public function test_only_valid_from_set_does_not_throw(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validFrom: new DateTimeImmutable('2026-01-01'),
        );

        $this->assertNotNull($promotion->validFrom());
        $this->assertNull($promotion->validUntil());
    }

    public function test_only_valid_until_set_does_not_throw(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validUntil: new DateTimeImmutable('2026-03-01'),
        );

        $this->assertNull($promotion->validFrom());
        $this->assertNotNull($promotion->validUntil());
    }

    public function test_both_valid_from_and_valid_until_null_does_not_throw(): void
    {
        $promotion = Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);

        $this->assertNull($promotion->validFrom());
        $this->assertNull($promotion->validUntil());
    }

    // --- isValidAt() (both-null = always valid) -----------------------------

    public function test_is_valid_at_with_no_bounds_is_always_valid(): void
    {
        $promotion = Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);

        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2000-01-01')));
        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2100-01-01')));
    }

    public function test_is_valid_at_with_only_valid_from_set(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validFrom: new DateTimeImmutable('2026-01-01'),
        );

        $this->assertFalse($promotion->isValidAt(new DateTimeImmutable('2025-12-31')));
        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2026-01-01')));
        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2099-01-01')));
    }

    public function test_is_valid_at_with_only_valid_until_set(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validUntil: new DateTimeImmutable('2026-03-01'),
        );

        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2000-01-01')));
        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2026-03-01')));
        $this->assertFalse($promotion->isValidAt(new DateTimeImmutable('2026-03-02')));
    }

    public function test_is_valid_at_with_both_bounds_set_inside_outside_and_on_the_boundary(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: new DateTimeImmutable('2026-03-01'),
        );

        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2026-02-01')));

        $this->assertFalse($promotion->isValidAt(new DateTimeImmutable('2025-12-31')));
        $this->assertFalse($promotion->isValidAt(new DateTimeImmutable('2026-03-02')));

        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2026-01-01')));
        $this->assertTrue($promotion->isValidAt(new DateTimeImmutable('2026-03-01')));
    }

    // --- usage limits --------------------------------------------------------

    public function test_zero_usage_limit_total_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            usageLimitTotal: 0,
        );
    }

    public function test_negative_usage_limit_per_customer_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            usageLimitPerCustomer: -1,
        );
    }

    public function test_zero_usage_limit_items_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            usageLimitItems: 0,
        );
    }

    public function test_positive_usage_limits_succeed(): void
    {
        $promotion = Promotion::create(
            'SUMMER20',
            PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            usageLimitTotal: 100,
            usageLimitPerCustomer: 1,
            usageLimitItems: 5,
        );

        $this->assertSame(100, $promotion->usageLimitTotal());
        $this->assertSame(1, $promotion->usageLimitPerCustomer());
        $this->assertSame(5, $promotion->usageLimitItems());
    }

    public function test_null_usage_limits_are_unlimited(): void
    {
        $promotion = Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);

        $this->assertNull($promotion->usageLimitTotal());
        $this->assertNull($promotion->usageLimitPerCustomer());
        $this->assertNull($promotion->usageLimitItems());
    }

    // --- activate() / deactivate() -------------------------------------------

    public function test_deactivate_then_activate_round_trips_status(): void
    {
        $promotion = Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);

        $promotion->deactivate();
        $this->assertSame(PromotionStatus::INACTIVE, $promotion->status());
        $this->assertFalse($promotion->isActive());

        $promotion->activate();
        $this->assertSame(PromotionStatus::ACTIVE, $promotion->status());
        $this->assertTrue($promotion->isActive());
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $promotion = Promotion::create('SUMMER20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);
        $promotion->assignId('1');

        $this->assertSame('1', $promotion->id());

        $this->expectException(LogicException::class);
        $promotion->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $validFrom = new DateTimeImmutable('2026-01-01');
        $validUntil = new DateTimeImmutable('2026-03-01');
        $minimumSpend = Money::fromDecimal('50.00', 'EUR');
        $maximumSpend = Money::fromDecimal('100.00', 'EUR');

        $promotion = Promotion::reconstituteFromStorage(
            id: '7',
            code: 'summer20',
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 2000,
            discountAmount: null,
            individualUseOnly: true,
            excludeSaleItems: true,
            minimumSpend: $minimumSpend,
            maximumSpend: $maximumSpend,
            newCustomersOnly: true,
            usageLimitTotal: 100,
            usageLimitPerCustomer: 1,
            usageLimitItems: 5,
            validFrom: $validFrom,
            validUntil: $validUntil,
            status: PromotionStatus::INACTIVE,
        );

        $this->assertSame('7', $promotion->id());
        $this->assertSame('summer20', $promotion->code());
        $this->assertSame(PromotionDiscountType::PERCENTAGE, $promotion->discountType());
        $this->assertSame(2000, $promotion->percentageBasisPoints());
        $this->assertNull($promotion->discountAmount());
        $this->assertTrue($promotion->individualUseOnly());
        $this->assertTrue($promotion->excludeSaleItems());
        $this->assertSame($minimumSpend, $promotion->minimumSpend());
        $this->assertSame($maximumSpend, $promotion->maximumSpend());
        $this->assertTrue($promotion->newCustomersOnly());
        $this->assertSame(100, $promotion->usageLimitTotal());
        $this->assertSame(1, $promotion->usageLimitPerCustomer());
        $this->assertSame(5, $promotion->usageLimitItems());
        $this->assertSame($validFrom, $promotion->validFrom());
        $this->assertSame($validUntil, $promotion->validUntil());
        $this->assertSame(PromotionStatus::INACTIVE, $promotion->status());
    }

    public function test_reconstitute_from_storage_can_rebuild_a_fixed_amount_promotion(): void
    {
        $discountAmount = Money::fromDecimal('10.00', 'EUR');

        $promotion = Promotion::reconstituteFromStorage(
            id: '9',
            code: 'tenoff',
            discountType: PromotionDiscountType::FIXED_AMOUNT,
            percentageBasisPoints: null,
            discountAmount: $discountAmount,
            individualUseOnly: false,
            excludeSaleItems: false,
            minimumSpend: null,
            maximumSpend: null,
            newCustomersOnly: false,
            usageLimitTotal: null,
            usageLimitPerCustomer: null,
            usageLimitItems: null,
            validFrom: null,
            validUntil: null,
            status: PromotionStatus::ACTIVE,
        );

        $this->assertSame(PromotionDiscountType::FIXED_AMOUNT, $promotion->discountType());
        $this->assertSame($discountAmount, $promotion->discountAmount());
    }
}
