<?php

namespace Tests\Unit;

use App\Services\OrderEditFormMapper;
use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * order-editing-design.md §8 (D7), stage 4b-i — the dialog's form-to-service
 * mapping, in isolation: no Filament, no database.
 */
class OrderEditFormMapperTest extends TestCase
{
    // --- the promotion code's three states ------------------------------------------------

    /** @return array<string, array{?string, ?string, bool, string, ?string}> current, typed, remove toggle, expected state, expected code */
    public static function promotionCases(): array
    {
        return [
            'no code, nothing typed' => [null, null, false, 'unchanged', null],
            'no code, blank typed' => [null, '', false, 'unchanged', null],
            'no code, spaces typed' => [null, '   ', false, 'unchanged', null],
            'no code, a code typed' => [null, 'SUMMER', false, 'set', 'SUMMER'],
            'no code, a code typed with padding' => [null, '  SUMMER ', false, 'set', 'SUMMER'],
            'current code, field untouched' => ['summer', 'summer', false, 'unchanged', null],
            'current code, same code in another case' => ['summer', 'SUMMER', false, 'unchanged', null],
            'current code, same code padded' => ['summer', ' summer ', false, 'unchanged', null],
            'current code, field cleared and toggle off: a blank never removes' => ['summer', '', false, 'unchanged', null],
            'current code, field nulled and toggle off' => ['summer', null, false, 'unchanged', null],
            'current code, another code typed' => ['summer', 'winter', false, 'set', 'winter'],
            'current code, toggle on, text untouched' => ['summer', 'summer', true, 'removed', null],
            'current code, toggle on, text cleared' => ['summer', '', true, 'removed', null],
            'current code, toggle on, ANOTHER code typed: the toggle wins' => ['summer', 'winter', true, 'removed', null],
            'no code, toggle on' => [null, null, true, 'removed', null],
            'no code, toggle on, a code typed: the toggle wins' => [null, 'SUMMER', true, 'removed', null],
        ];
    }

    #[DataProvider('promotionCases')]
    public function test_the_promotion_code_three_state_mapping(?string $current, ?string $typed, bool $remove, string $state, ?string $code): void
    {
        $change = OrderEditFormMapper::promotionCodeChange($current, $typed, $remove);

        $this->assertSame($state === 'unchanged', $change->isUnchanged());
        $this->assertSame($state === 'set', $change->isSet());
        $this->assertSame($state === 'removed', $change->isRemoved());

        if ($code !== null) {
            $this->assertSame($code, $change->code());
        }
    }

    // --- delivery ----------------------------------------------------------------------------

    private function street(): array
    {
        return [
            'delivery_type' => 'street_address', 'recipient_name' => 'Ivan', 'phone' => '+359888', 'country' => 'BG', 'city' => 'Sofia',
            'postal_code' => null, 'address_line_1' => 'Vitosha 1', 'address_line_2' => null,
            'carrier_code' => null, 'pickup_point_reference' => null, 'settlement' => null,
        ];
    }

    public function test_an_identical_delivery_is_no_change(): void
    {
        $this->assertNull(OrderEditFormMapper::deliveryChange($this->street(), $this->street()));
    }

    public function test_blank_and_null_are_the_same_fact_for_an_optional_field(): void
    {
        $submitted = $this->street();
        $submitted['postal_code'] = '';
        $submitted['address_line_2'] = '  ';
        unset($submitted['carrier_code']);

        $this->assertNull(OrderEditFormMapper::deliveryChange($this->street(), $submitted));
    }

    public function test_a_changed_field_yields_the_whole_replacement_snapshot(): void
    {
        $submitted = $this->street();
        $submitted['city'] = ' Plovdiv ';
        $submitted['carrier_code'] = 'econt';

        $change = OrderEditFormMapper::deliveryChange($this->street(), $submitted);

        $this->assertSame(OrderDeliveryType::STREET_ADDRESS, $change->deliveryType);
        $this->assertSame('Plovdiv', $change->city);
        $this->assertSame('econt', $change->carrierCode);
        $this->assertSame('Vitosha 1', $change->addressLine1, 'untouched fields are carried, not dropped');
        $this->assertNull($change->pickupPointReference);
    }

    public function test_switching_to_a_pickup_point_drops_the_street_fields_the_form_no_longer_sends(): void
    {
        $change = OrderEditFormMapper::deliveryChange($this->street(), [
            'delivery_type' => 'pickup_point', 'recipient_name' => 'Ivan', 'phone' => '+359888',
            'carrier_code' => 'speedy', 'pickup_point_reference' => 'office-7', 'settlement' => 'Sofia',
        ]);

        $this->assertSame(OrderDeliveryType::PICKUP_POINT, $change->deliveryType);
        $this->assertNull($change->city);
        $this->assertNull($change->addressLine1);
        $this->assertSame('office-7', $change->pickupPointReference);
    }

    // --- lines ---------------------------------------------------------------------------------

    private function line(string $id, int $quantity, int $discretionaryMinor = 0): SaleLine
    {
        $unit = Money::fromMinorUnits(1000, 'EUR');
        $line = SaleLine::create(
            transactionId: '1',
            clientId: '1',
            priceableId: 'variation-'.$id,
            status: SaleLineStatus::COMPLETED,
            quantity: $quantity,
            amount: $unit->multiply($quantity),
            profit: Money::zero('EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'Product '.$id,
            sku: 'SKU-'.$id,
            regularUnitPrice: $unit,
            finalUnitPrice: $unit,
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::fromMinorUnits($discretionaryMinor, 'EUR'),
            netPaidAmount: $unit->multiply($quantity)->subtract(Money::fromMinorUnits($discretionaryMinor, 'EUR')),
            soldAttributes: [],
        );
        $line->assignId($id);

        return $line;
    }

    /** @return array<string, SaleLine> */
    private function lines(): array
    {
        return ['10' => $this->line('10', 3), '11' => $this->line('11', 2, 200)];
    }

    public function test_an_untouched_row_contributes_nothing(): void
    {
        $changes = OrderEditFormMapper::lineChanges([
            'k1' => ['line_id' => '10', 'quantity' => 3, 'discount' => '0.00'],
            'k2' => ['line_id' => '11', 'quantity' => '2', 'discount' => '2.00'],
        ], $this->lines(), true, 'EUR');

        $this->assertSame([], $changes);
    }

    public function test_rows_are_read_by_the_hidden_line_id_never_by_the_item_key(): void
    {
        $changes = OrderEditFormMapper::lineChanges([
            'a1b2-uuid' => ['line_id' => '11', 'quantity' => 1],
        ], $this->lines(), false, 'EUR');

        $this->assertCount(1, $changes);
        $this->assertSame('11', $changes[0]['originatingLine']->id());
    }

    public function test_reducing_zero_and_blank_quantities(): void
    {
        $changes = OrderEditFormMapper::lineChanges([
            ['line_id' => '10', 'quantity' => 2],
            ['line_id' => '11', 'quantity' => 0],
        ], $this->lines(), true, 'EUR');

        $this->assertSame(['change_quantity', 'remove'], array_column($changes, 'change'));
        $this->assertSame(2, $changes[0]['quantity']);

        $blank = OrderEditFormMapper::lineChanges([['line_id' => '10', 'quantity' => '']], $this->lines(), true, 'EUR');
        $this->assertSame([], $blank, 'a blank quantity means "leave it"');
    }

    public function test_a_row_that_changes_quantity_and_discount_yields_the_two_entries_the_editor_merges(): void
    {
        $changes = OrderEditFormMapper::lineChanges([
            ['line_id' => '10', 'quantity' => 1, 'discount' => '1.50'],
        ], $this->lines(), true, 'EUR');

        $this->assertSame(['change_quantity', 'discount'], array_column($changes, 'change'));
        $this->assertSame(150, $changes[1]['discretionaryDiscount']->minorValue());
    }

    public function test_a_removed_row_ignores_its_discount(): void
    {
        $changes = OrderEditFormMapper::lineChanges([['line_id' => '10', 'quantity' => 0, 'discount' => '5.00']], $this->lines(), true, 'EUR');

        $this->assertSame(['remove'], array_column($changes, 'change'));
    }

    public function test_the_discount_is_never_read_without_the_permission(): void
    {
        $changes = OrderEditFormMapper::lineChanges([['line_id' => '10', 'quantity' => 3, 'discount' => '9.00']], $this->lines(), false, 'EUR');

        $this->assertSame([], $changes);
    }

    #[DataProvider('refusedRows')]
    public function test_a_malformed_or_tampered_row_is_refused(array $rows): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderEditFormMapper::lineChanges($rows, $this->lines(), true, 'EUR');
    }

    /** @return array<string, array{array<int, array<string, mixed>>}> */
    public static function refusedRows(): array
    {
        return [
            'an unknown line id' => [[['line_id' => '99', 'quantity' => 1]]],
            'no line id at all' => [[['quantity' => 1]]],
            'the same line twice' => [[['line_id' => '10', 'quantity' => 1], ['line_id' => '10', 'quantity' => 2]]],
            'a raised quantity' => [[['line_id' => '10', 'quantity' => 4]]],
            'a negative quantity' => [[['line_id' => '10', 'quantity' => -1]]],
            'a fractional quantity' => [[['line_id' => '10', 'quantity' => '1.5']]],
            'a non-numeric quantity' => [[['line_id' => '10', 'quantity' => 'abc']]],
        ];
    }

    // --- the "add a product" section (stage 4b-ii) -----------------------------------------

    public function test_an_untouched_add_section_asks_for_nothing(): void
    {
        $this->assertNull(OrderEditFormMapper::addLineRequest([]));
        $this->assertNull(OrderEditFormMapper::addLineRequest(['variation_id' => null, 'quantity' => 1]));
        $this->assertNull(OrderEditFormMapper::addLineRequest(['variation_id' => '', 'quantity' => 1]));
        $this->assertNull(OrderEditFormMapper::addLineRequest(['variation_id' => '   ', 'quantity' => 3]));
    }

    public function test_a_picked_variation_and_a_quantity_become_one_add_request(): void
    {
        $this->assertSame(
            ['variationId' => '42', 'quantity' => 2],
            OrderEditFormMapper::addLineRequest(['variation_id' => '42', 'quantity' => 2]),
        );

        $this->assertSame(
            ['variationId' => '42', 'quantity' => 1],
            OrderEditFormMapper::addLineRequest(['variation_id' => ' 42 ', 'quantity' => '1']),
            'a padded id and a numeric string quantity are both normalised',
        );

        $this->assertSame(
            ['variationId' => '42', 'quantity' => 5],
            OrderEditFormMapper::addLineRequest(['variation_id' => 42, 'quantity' => 5]),
            'an int id — a Select\'s own state can legitimately arrive either way',
        );
    }

    #[DataProvider('refusedAdds')]
    public function test_a_picked_variation_with_no_usable_quantity_is_refused(array $submitted): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderEditFormMapper::addLineRequest($submitted);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function refusedAdds(): array
    {
        return [
            'no quantity at all' => [['variation_id' => '42']],
            'a blank quantity' => [['variation_id' => '42', 'quantity' => '']],
            'a null quantity' => [['variation_id' => '42', 'quantity' => null]],
            'zero' => [['variation_id' => '42', 'quantity' => 0]],
            'a negative quantity' => [['variation_id' => '42', 'quantity' => -2]],
            'a fractional quantity' => [['variation_id' => '42', 'quantity' => '1.5']],
            'a non-numeric quantity' => [['variation_id' => '42', 'quantity' => 'two']],
        ];
    }
}
