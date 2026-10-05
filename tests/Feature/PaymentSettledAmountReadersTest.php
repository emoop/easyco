<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\MoneyOnlyRefundRequest;
use App\Services\MoneyOnlyRefunder;
use App\Services\OrderRefundsReader;
use App\Services\RefundCapGuard;
use App\Services\Exceptions\RefundCapExceededException;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\RefundChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §4): the three readers of "what was paid in" read
 * Payment::settledAmount(), not amount(). A bank-transfer payment of 100.00 settled for an ACCEPTED 90.00
 * gives total room 90.00, paid-in 90.00 and a "Paid" row of 90.00; a payment without an accepted amount
 * behaves exactly as before.
 */
class PaymentSettledAmountReadersTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    /** A shipped bank-transfer order whose payment of 100.00 was settled; $accepted (minor) marks an accepted mismatch. */
    private function order(?int $accepted = null): array
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer');

        if ($accepted !== null) {
            DB::table('payments')->where('id', $order['payment']->id())->update(['settled_amount_minor' => $accepted, 'settlement_reason' => 'accepted a short transfer']);
        }

        return $order;
    }

    private function payment(array $order)
    {
        return app(PaymentRepository::class)->findById((string) $order['payment']->id());
    }

    public function test_the_total_room_of_a_payment_accepted_at_90_of_100_is_90(): void
    {
        $order = $this->order(accepted: 9000);
        $payment = $this->payment($order);

        $this->assertSame(10000, $payment->amount()->minorValue());
        $this->assertSame(9000, app(RefundCapGuard::class)->totalRoom($payment)->minorValue());
    }

    public function test_the_total_room_of_a_payment_without_an_accepted_amount_is_what_it_always_was(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);

        $this->assertSame(10000, app(RefundCapGuard::class)->totalRoom($payment)->minorValue(), 'amount - refunds, byte for byte');

        app(MoneyOnlyRefunder::class)->record($order['orderId'], new MoneyOnlyRefundRequest(shipping: $this->eur(0), adjustment: $this->eur(2500), reason: 'goodwill', channel: RefundChannel::BANK), $this->at());

        $this->assertSame(7500, app(RefundCapGuard::class)->totalRoom($this->payment($order))->minorValue());
    }

    public function test_the_refunds_reader_counts_the_accepted_amount_as_paid_in(): void
    {
        $order = $this->order(accepted: 9000);
        app(MoneyOnlyRefunder::class)->record($order['orderId'], new MoneyOnlyRefundRequest(shipping: $this->eur(0), adjustment: $this->eur(2000), reason: 'goodwill', channel: RefundChannel::BANK), $this->at());

        $figures = app(OrderRefundsReader::class)->forOrder($order['orderId'], 'EUR')['figures'];

        $this->assertSame(9000, $figures['paid_in']);
        $this->assertSame(2000, $figures['owed']);
        $this->assertSame(7000, $figures['still_refundable'], 'paid in minus every counting refund');
    }

    public function test_the_refunds_reader_without_an_accepted_amount_is_unchanged(): void
    {
        $order = $this->order();
        app(MoneyOnlyRefunder::class)->record($order['orderId'], new MoneyOnlyRefundRequest(shipping: $this->eur(0), adjustment: $this->eur(2000), reason: 'goodwill', channel: RefundChannel::BANK), $this->at());

        $figures = app(OrderRefundsReader::class)->forOrder($order['orderId'], 'EUR')['figures'];

        $this->assertSame(10000, $figures['paid_in']);
        $this->assertSame(8000, $figures['still_refundable']);
    }

    public function test_the_paid_row_of_the_money_summary_shows_the_accepted_amount(): void
    {
        $accepted = $this->order(accepted: 9000);
        $html = Livewire::test(ViewOrder::class, ['record' => $accepted['orderId']])->html();
        $this->assertStringContainsString('90.00', $html, 'the "Paid" row');

        $this->app->forgetScopedInstances();
        $plain = $this->order();
        $html = Livewire::test(ViewOrder::class, ['record' => $plain['orderId']])->html();
        $this->assertStringContainsString('100.00', $html, 'a payment without an accepted amount shows its amount');
    }

    public function test_a_refund_above_the_accepted_amount_is_refused_by_the_total_cap(): void
    {
        $order = $this->order(accepted: 9000);

        try {
            app(MoneyOnlyRefunder::class)->record($order['orderId'], new MoneyOnlyRefundRequest(shipping: $this->eur(0), adjustment: $this->eur(9500), reason: 'too much', channel: RefundChannel::BANK), $this->at());
            $this->fail('95.00 against a payment settled for 90.00.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::TOTAL, $exception->cap());
            $this->assertSame(9000, $exception->room()->minorValue());
        }

        $this->assertSame(0, DB::table('payment_refunds')->count());

        app(MoneyOnlyRefunder::class)->record($order['orderId'], new MoneyOnlyRefundRequest(shipping: $this->eur(0), adjustment: $this->eur(9000), reason: 'all of it', channel: RefundChannel::BANK), $this->at());
        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_an_overpayment_accepted_at_110_raises_the_cap_to_110(): void
    {
        $order = $this->order(accepted: 11000);

        $this->assertSame(11000, app(RefundCapGuard::class)->totalRoom($this->payment($order))->minorValue());
    }
}
