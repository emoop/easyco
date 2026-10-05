<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\OrderStatusChanger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 part 2 follow-up 2: the quantity box of the RETURN dialog. Whole numbers only; on blur an
 * over-max value becomes what remains and the goods box follows the CORRECTED quantity; a negative or
 * non-numeric value is emptied; at most six digits; maxValue and the service stay the final guards.
 */
class OrderReturnQuantityBoxTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function dialog(string $orderId): Testable
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId])->mountAction('record_return');
    }

    private function lastNotificationBody(): ?string
    {
        $component = new \Filament\Notifications\Livewire\Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    private function data(Testable $component): array
    {
        return $component->instance()->mountedActions[0]['data'];
    }

    public function test_a_quantity_above_the_remaining_becomes_the_remaining_and_the_goods_follow_it(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        $component = $this->dialog($order['orderId'])->set("mountedActions.0.data.quantity.{$line}", 9);

        $this->assertSame(5, (int) $this->data($component)['quantity'][$line], 'clamped to what remains');
        $this->assertSame('50.00', $this->data($component)['goods'][$line], 'the computed share of 5 units — not blank because 9 was too high');
    }

    public function test_a_line_with_3_ordered_and_1_returned_clamps_to_2_not_3(): void
    {
        $order = $this->refundableOrder([['quantity' => 3, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        app(OrderStatusChanger::class)->recordReturn($order['orderId'], [['originatingSaleLineId' => $line, 'quantityReturned' => 1, 'restock' => true]], $this->at());

        $component = $this->dialog($order['orderId'])->set("mountedActions.0.data.quantity.{$line}", 3);

        $this->assertSame(2, (int) $this->data($component)['quantity'][$line]);
        $this->assertSame('20.00', $this->data($component)['goods'][$line], 'cumulative(3) - cumulative(1) of 30.00');
    }

    public function test_a_negative_or_non_numeric_quantity_is_emptied_and_the_goods_box_with_it(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $component = $this->dialog($order['orderId']);

        foreach (['-2', 'abc', ''] as $typed) {
            $component->set("mountedActions.0.data.quantity.{$line}", 2)->set("mountedActions.0.data.quantity.{$line}", $typed);

            $this->assertNull($this->data($component)['quantity'][$line] ?? null, "{$typed} becomes empty");
            $this->assertNull($this->data($component)['goods'][$line] ?? null);
        }

        // Submitting a negative: the blur hook has emptied it (0), so there is nothing to return — a notice, nothing written.
        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: ['quantity' => [$line => -2], 'restock' => [$line => true]]);
        $this->assertSame(__('orders.actions.nothing_to_return'), $this->lastNotificationBody());
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    public function test_a_decimal_quantity_is_a_field_error_and_is_never_cut_to_an_integer(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        $component = $this->dialog($order['orderId'])->set("mountedActions.0.data.quantity.{$line}", '1.5');
        $this->assertSame('1.5', (string) $this->data($component)['quantity'][$line], 'left as typed for the integer rule — not silently cut to 1');
        $this->assertNull($this->data($component)['goods'][$line] ?? null, 'and no share is computed for it');

        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: ['quantity' => [$line => '1.5'], 'restock' => [$line => true]])
            ->assertHasActionErrors(["quantity.{$line}"]);

        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    public function test_a_30_digit_quantity_is_a_field_error_and_nothing_is_written(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $huge = str_repeat('9', 30);

        $component = $this->dialog($order['orderId'])->set("mountedActions.0.data.quantity.{$line}", $huge);
        $this->assertNull($this->data($component)['goods'][$line] ?? null, 'the hook does not cast it');

        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: ['quantity' => [$line => $huge], 'restock' => [$line => true]])
            ->assertHasActionErrors(["quantity.{$line}"]);

        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    public function test_the_box_keeps_its_submit_time_rules_and_a_submitted_over_max_value_is_the_corrected_one(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        // The rules stay on the box: whole numbers, at least 0, at most what remains, at most six digits.
        $box = $this->dialog($order['orderId'])->instance();
        $schema = (new \ReflectionMethod($box, 'getMountedActionSchema'))->invoke($box);
        $quantity = $schema->getFlatFields()["quantity.{$line}"];
        $this->assertTrue($quantity->isInteger());
        $this->assertSame(0, $quantity->getMinValue());
        $this->assertSame(5, $quantity->getMaxValue());
        $this->assertSame(6, $quantity->getMaxLength());

        // The blur hook runs before a submit, so 9 is submitted as the 5 that remain; the goods are those 5 units' share.
        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: ['quantity' => [$line => 9], 'restock' => [$line => true]]);
        $this->assertSame([5], DB::table('operational_sales_sale_lines')->where('type', 'refund')->pluck('quantity_returned')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(5000, (int) DB::table('payment_refunds')->value('amount_minor'));

        // The service's own guard (ReturnExceedsRemainingQuantityException, a notice) is still the final one: the stale-form race
        // test of OrderCancelReturnActionsTest reaches it, and this order has nothing left to return.
    }

    public function test_a_double_submit_of_a_corrected_quantity_still_records_once(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];
        $data = ['quantity' => [$line => 9], 'restock' => [$line => true], 'operation_key' => 'qty-one-opening'];
        $data['quantity'][$line] = 2; // a partial return, so the order is still returnable for the second submit

        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: $data);
        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->callAction('record_return', data: $data);

        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'the 2 units came back once');
    }
}
