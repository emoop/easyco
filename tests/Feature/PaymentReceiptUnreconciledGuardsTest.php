<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\Exceptions\PaymentReceiptUnreconciledException;
use App\Services\OrderDeliveryChange;
use App\Services\OrderEditor;
use App\Services\OrderPaymentConfirmer;
use App\Services\OrderPromotionCodeChange;
use App\Services\OrderStatusChanger;
use App\Services\PaymentReceiptRecorder;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Refunds R4a-2 (shipping-domain-design.md §7.2.20 §3): while a bank transfer was received for an order and
 * has not been reconciled (an effective receipt on a payment that is not settled), cancel, a return and an
 * order edit are refused BY NAME — they would void the pending payment and orphan the money — and so is the
 * old one-click confirmation, which would settle for the full expected amount over a receipt that does not
 * match it. An order with no receipts behaves exactly as before.
 */
class PaymentReceiptUnreconciledGuardsTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function receive(array $order, int $minor, string $reference = 'BG-REF-1'): void
    {
        app(PaymentReceiptRecorder::class)->record((string) $order['payment']->id(), $this->eur($minor), '2026-09-28', $reference, $this->at());
    }

    private function snapshot(string $orderId): array
    {
        return [
            DB::table('orders')->where('id', $orderId)->value('status'),
            DB::table('order_events')->where('order_id', $orderId)->count(),
            DB::table('payments')->where('order_id', $orderId)->count(),
            DB::table('payments')->where('order_id', $orderId)->whereNotNull('voided_at')->count(),
            DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(),
            DB::table('payment_refunds')->count(),
        ];
    }

    private function deliveryChange(): OrderDeliveryChange
    {
        return new OrderDeliveryChange(
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'New Recipient',
            phone: '+359888999999',
            country: 'BG',
            city: 'Plovdiv',
            addressLine1: 'Main Street 5',
            carrierCode: 'econt',
        );
    }

    private function edit(string $orderId): void
    {
        app(OrderEditor::class)->apply(
            orderId: $orderId,
            expectedRevision: (int) DB::table('orders')->where('id', $orderId)->value('edit_revision'),
            lineChanges: [],
            delivery: $this->deliveryChange(),
            promotionCode: OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: null,
            occurredAt: $this->at(),
        );
    }

    // --- cancel ---------------------------------------------------------------------------------------------

    public function test_cancel_is_refused_while_a_short_transfer_is_unreconciled(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $before = $this->snapshot($order['orderId']);

        try {
            app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());
            $this->fail('a cancel would void the payment and orphan the money in hand.');
        } catch (PaymentReceiptUnreconciledException $exception) {
            $this->assertSame(9000, $exception->received()->minorValue());
            $this->assertStringContainsString('90.00 EUR', $exception->getMessage());
            $this->assertStringContainsString('has not been reconciled', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($order['orderId']), 'nothing written: not voided, not cancelled, no refund');
    }

    public function test_cancel_is_refused_while_an_over_transfer_is_unreconciled_and_the_text_is_bulgarian_in_bg(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 12000);
        App::setLocale('bg');

        try {
            app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());
            $this->fail('refused by name.');
        } catch (PaymentReceiptUnreconciledException $exception) {
            $this->assertStringContainsString('120.00 EUR', $exception->getMessage());
            $this->assertStringContainsString('не е сверен', $exception->getMessage());
        }
    }

    public function test_cancel_works_for_a_pending_bank_order_with_no_receipts(): void
    {
        $order = $this->bankOrder();

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_cancel_works_once_the_receipts_settled_the_payment(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 6000, 'A');
        $this->receive($order, 4000, 'B');
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_cancel_works_when_the_only_receipts_belong_to_a_voided_payment(): void
    {
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 5000);
        $order['payment']->void(new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_the_cancel_guard_does_not_touch_the_receipts_of_a_cash_on_delivery_order(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
        $this->assertSame([], array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'payment_receipts'))), 'cash on delivery reads no receipts');
    }

    // --- return ---------------------------------------------------------------------------------------------

    public function test_a_return_is_refused_while_a_transfer_is_unreconciled(): void
    {
        $order = $this->bankOrder(OrderStatus::SHIPPED);
        $this->receive($order, 9000);
        $before = $this->snapshot($order['orderId']);

        $this->expectException(PaymentReceiptUnreconciledException::class);

        try {
            app(OrderStatusChanger::class)->recordReturn(
                $order['orderId'],
                [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]],
                $this->at(),
            );
        } finally {
            $this->assertSame($before, $this->snapshot($order['orderId']));
        }
    }

    public function test_a_return_works_for_an_order_with_no_receipts(): void
    {
        $order = $this->bankOrder(OrderStatus::SHIPPED);

        app(OrderStatusChanger::class)->recordReturn(
            $order['orderId'],
            [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]],
            $this->at(),
        );

        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'returned')->count());
    }

    // --- edit -----------------------------------------------------------------------------------------------

    public function test_an_edit_is_refused_while_a_transfer_is_unreconciled(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $before = $this->snapshot($order['orderId']);

        $this->expectException(PaymentReceiptUnreconciledException::class);

        try {
            $this->edit($order['orderId']);
        } finally {
            $this->assertSame($before, $this->snapshot($order['orderId']));
            $this->assertSame(0, (int) DB::table('orders')->where('id', $order['orderId'])->value('edit_revision'));
        }
    }

    public function test_an_edit_works_for_an_order_with_no_receipts(): void
    {
        $order = $this->bankOrder();

        $this->edit($order['orderId']);

        $this->assertSame('Plovdiv', DB::table('orders')->where('id', $order['orderId'])->value('city'));
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order['orderId'])->value('edit_revision'));
    }

    // --- the old one-click confirmation ---------------------------------------------------------------------

    public function test_the_one_click_confirmation_of_a_bank_transfer_with_a_mismatching_receipt_is_refused(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $before = $this->snapshot($order['orderId']);

        try {
            app(OrderPaymentConfirmer::class)->confirm((string) $order['payment']->id(), $this->at());
            $this->fail('the one-click path would settle for the full 100.00 over a 90.00 receipt.');
        } catch (PaymentReceiptUnreconciledException $exception) {
            $this->assertSame(9000, $exception->received()->minorValue());
        }

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame($before, $this->snapshot($order['orderId']));
    }

    public function test_the_one_click_confirmation_of_an_over_transfer_is_refused_too(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 11000);

        $this->expectException(PaymentReceiptUnreconciledException::class);

        app(OrderPaymentConfirmer::class)->confirm((string) $order['payment']->id(), $this->at());
    }

    public function test_the_one_click_confirmation_without_receipts_works_exactly_as_before(): void
    {
        $order = $this->bankOrder();

        app(OrderPaymentConfirmer::class)->confirm((string) $order['payment']->id(), $this->at());

        $row = $this->paymentRow($order['payment']);
        $this->assertSame('2026-09-28 12:00:00', $row->confirmed_at);
        $this->assertNull($row->settled_amount_minor);
        $this->assertSame(['payment_confirmed'], DB::table('order_events')->where('order_id', $order['orderId'])->pluck('type')->all());
        $this->assertSame(0, DB::table('payment_receipts')->count());
    }

    public function test_the_one_click_confirmation_of_cash_on_delivery_reads_no_receipts_and_works(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(OrderPaymentConfirmer::class)->confirm((string) $order['payment']->id(), $this->at());
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame([], array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'payment_receipts'))));
    }

    public function test_the_receipt_services_own_exact_match_still_settles_through_the_confirmer(): void
    {
        $order = $this->bankOrder();

        $this->receive($order, 10000);

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_the_panel_no_longer_offers_a_one_click_confirmation_of_a_bank_transfer(): void
    {
        // Refunds R4a-4 replaced the one-click "mark as received" of a bank transfer with the record dialog (this test
        // used to call it bare and expect the service's refusal as a notice; the service backstop is tested above).
        // Submitted without the dialog's fields the action is a validation error and nothing is written.
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        Livewire::test(ViewOrder::class, ['record' => $order['orderId']])
            ->callAction('mark_as_received')
            ->assertHasActionErrors(['bank_reference' => 'required']);

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_a_failed_payment_with_no_receipts_is_not_affected(): void
    {
        // A guard that only looks at bank-transfer payments that are not settled and not voided.
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer', settle: false);
        $payment = Payment::create($order['orderId'], 'bank_transfer', $this->eur(10000), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }
}
