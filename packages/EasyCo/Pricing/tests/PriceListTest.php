<?php

namespace EasyCo\Pricing\Tests;

use DateTimeImmutable;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListStatus;
use EasyCo\Pricing\Exceptions\CannotModifySystemPriceListException;
use EasyCo\Pricing\PriceList;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PriceListTest extends TestCase
{
    // --- create() -----------------------------------------------------

    public function test_create_produces_a_non_system_active_list(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->assertNull($list->id());
        $this->assertSame('Wholesale', $list->name());
        $this->assertSame(PriceListMode::FIXED_ITEMS, $list->mode());
        $this->assertSame(10, $list->priority());
        $this->assertFalse($list->isSystem());
        $this->assertSame(PriceListStatus::ACTIVE, $list->status());
        $this->assertTrue($list->isActive());
    }

    // --- createSystemList() --------------------------------------------

    public function test_create_system_list_produces_a_system_active_list_with_no_time_limit(): void
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);

        $this->assertTrue($list->isSystem());
        $this->assertSame(PriceListStatus::ACTIVE, $list->status());
        $this->assertNull($list->validFrom());
        $this->assertNull($list->validUntil());
    }

    public function test_create_system_list_has_no_validity_window_parameters_at_all(): void
    {
        // Confirms the factory's own signature — not just that the
        // produced values happen to be null (covered above), but that
        // there is no parameter through which a caller could even try
        // to pass a time-boxed window in the first place.
        $reflection = new \ReflectionMethod(PriceList::class, 'createSystemList');
        $parameterNames = array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            $reflection->getParameters()
        );

        $this->assertNotContains('validFrom', $parameterNames);
        $this->assertNotContains('validUntil', $parameterNames);
    }

    // --- percentageBasisPoints / mode consistency -----------------------

    public function test_percentage_off_regular_requires_percentage_basis_points(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10);
    }

    public function test_percentage_off_regular_rejects_a_negative_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceList::create(
            'Guess -20%',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            percentageBasisPoints: -1
        );
    }

    public function test_percentage_off_regular_accepts_a_valid_percentage(): void
    {
        $list = PriceList::create(
            'Guess -20%',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            percentageBasisPoints: 2000
        );

        $this->assertSame(2000, $list->percentageBasisPoints());
    }

    public function test_fixed_items_rejects_a_percentage_basis_points_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceList::create(
            'Wholesale',
            PriceListMode::FIXED_ITEMS,
            priority: 10,
            percentageBasisPoints: 2000
        );
    }

    public function test_fixed_items_with_no_percentage_basis_points_succeeds(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->assertNull($list->percentageBasisPoints());
    }

    // --- validFrom / validUntil -----------------------------------------

    public function test_valid_until_equal_to_valid_from_throws(): void
    {
        $at = new DateTimeImmutable('2026-01-01');

        $this->expectException(InvalidArgumentException::class);

        PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validFrom: $at,
            validUntil: $at,
            percentageBasisPoints: 3000
        );
    }

    public function test_valid_until_before_valid_from_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: new DateTimeImmutable('2025-12-01'),
            percentageBasisPoints: 3000
        );
    }

    public function test_only_valid_from_set_does_not_throw(): void
    {
        $list = PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validFrom: new DateTimeImmutable('2026-01-01'),
            percentageBasisPoints: 3000
        );

        $this->assertNotNull($list->validFrom());
        $this->assertNull($list->validUntil());
    }

    public function test_only_valid_until_set_does_not_throw(): void
    {
        $list = PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validUntil: new DateTimeImmutable('2026-03-01'),
            percentageBasisPoints: 3000
        );

        $this->assertNull($list->validFrom());
        $this->assertNotNull($list->validUntil());
    }

    public function test_both_valid_from_and_valid_until_null_does_not_throw(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->assertNull($list->validFrom());
        $this->assertNull($list->validUntil());
    }

    // --- rename() -------------------------------------------------------

    public function test_rename_on_a_non_system_list_succeeds(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $list->rename('Wholesale (B2B)');

        $this->assertSame('Wholesale (B2B)', $list->name());
    }

    public function test_rename_on_a_non_system_list_with_an_empty_name_throws(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->expectException(InvalidArgumentException::class);
        $list->rename('');
    }

    public function test_rename_on_a_system_list_throws(): void
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);

        $this->expectException(CannotModifySystemPriceListException::class);
        $list->rename('Something Else');
    }

    // --- activate() / deactivate() ---------------------------------------

    public function test_deactivate_on_a_non_system_list_succeeds(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $list->deactivate();

        $this->assertSame(PriceListStatus::INACTIVE, $list->status());
        $this->assertFalse($list->isActive());
    }

    public function test_deactivate_on_a_system_list_throws(): void
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);

        $this->expectException(CannotModifySystemPriceListException::class);
        $list->deactivate();
    }

    public function test_activate_on_a_system_list_does_not_throw(): void
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);

        $list->activate();

        $this->assertSame(PriceListStatus::ACTIVE, $list->status());
    }

    public function test_activate_after_deactivate_on_a_non_system_list_works(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $list->deactivate();
        $list->activate();

        $this->assertTrue($list->isActive());
    }

    // --- assignId() -------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $list->assignId('1');

        $this->assertSame('1', $list->id());

        $this->expectException(LogicException::class);
        $list->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $validFrom = new DateTimeImmutable('2026-01-01');
        $validUntil = new DateTimeImmutable('2026-03-01');

        $list = PriceList::reconstituteFromStorage(
            id: '7',
            name: 'Autumn/Winter 2025',
            mode: PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 20,
            validFrom: $validFrom,
            validUntil: $validUntil,
            percentageBasisPoints: 3000,
            status: PriceListStatus::INACTIVE,
            isSystem: false,
        );

        $this->assertSame('7', $list->id());
        $this->assertSame('Autumn/Winter 2025', $list->name());
        $this->assertSame(PriceListMode::PERCENTAGE_OFF_REGULAR, $list->mode());
        $this->assertSame(20, $list->priority());
        $this->assertSame($validFrom, $list->validFrom());
        $this->assertSame($validUntil, $list->validUntil());
        $this->assertSame(3000, $list->percentageBasisPoints());
        $this->assertSame(PriceListStatus::INACTIVE, $list->status());
        $this->assertFalse($list->isSystem());
    }

    public function test_reconstitute_from_storage_can_rebuild_a_system_list(): void
    {
        $list = PriceList::reconstituteFromStorage(
            id: '1',
            name: 'Regular Prices',
            mode: PriceListMode::FIXED_ITEMS,
            priority: 0,
            validFrom: null,
            validUntil: null,
            percentageBasisPoints: null,
            status: PriceListStatus::ACTIVE,
            isSystem: true,
        );

        $this->assertTrue($list->isSystem());
    }

    // --- isValidAt() -------------------------------------------------------

    public function test_is_valid_at_with_no_bounds_is_always_valid(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2000-01-01')));
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2100-01-01')));
    }

    public function test_is_valid_at_with_only_valid_from_set(): void
    {
        $list = PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validFrom: new DateTimeImmutable('2026-01-01'),
            percentageBasisPoints: 3000
        );

        $this->assertFalse($list->isValidAt(new DateTimeImmutable('2025-12-31')));
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2026-01-01')));
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2099-01-01')));
    }

    public function test_is_valid_at_with_only_valid_until_set(): void
    {
        $list = PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validUntil: new DateTimeImmutable('2026-03-01'),
            percentageBasisPoints: 3000
        );

        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2000-01-01')));
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2026-03-01')));
        $this->assertFalse($list->isValidAt(new DateTimeImmutable('2026-03-02')));
    }

    public function test_is_valid_at_with_both_bounds_set_inside_outside_and_on_the_boundary(): void
    {
        $list = PriceList::create(
            'Autumn/Winter 2025',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: new DateTimeImmutable('2026-03-01'),
            percentageBasisPoints: 3000
        );

        // Inside the window.
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2026-02-01')));

        // Outside the window, on either side.
        $this->assertFalse($list->isValidAt(new DateTimeImmutable('2025-12-31')));
        $this->assertFalse($list->isValidAt(new DateTimeImmutable('2026-03-02')));

        // Exactly on each boundary — inclusive on both ends (see
        // PriceList::isValidAt()'s own docblock for why).
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2026-01-01')));
        $this->assertTrue($list->isValidAt(new DateTimeImmutable('2026-03-01')));
    }
}
