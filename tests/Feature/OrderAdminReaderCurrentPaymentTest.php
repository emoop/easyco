<?php

namespace Tests\Feature;

use App\Services\OrderAdminReader;
use DateTimeImmutable;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §7.3 / §11 item 19 (its §10 stage 5) — the voided_at
 * condition on OrderAdminReader's TWO payment reads.
 *
 * "CURRENT PAYMENT" IS ONE RULE, READ IN TWO PLACES: the newest payments row for
 * the order by attempted_at DESC, id DESC whose voided_at is NULL. This file
 * pins that both reads apply it — forOrder()'s View-page read and the Orders
 * list's payment-method filter, which share it through
 * latestPaymentColumnSubquery() — because the failure mode of a half-applied
 * rule is not "a wrong payment on one screen": it is the list and the View page
 * disagreeing about the same order.
 *
 * A VOIDED ROW STAYS VISIBLE. What a void removes is the row's claim to be the
 * order's current payment, never the attempt itself: the trail keeps every row,
 * and the view's payments list keeps carrying them (a display fact, deliberately not
 * the "current" rule).
 */
class OrderAdminReaderCurrentPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): OrderAdminReader
    {
        return app(OrderAdminReader::class);
    }

    /**
     * The reader memoizes its views per scoped() instance (AppServiceProvider),
     * so a genuine re-read needs a new one — the same discipline
     * OrderAdminReaderEventsTest already applies to its own measurements.
     */
    private function freshReader(): OrderAdminReader
    {
        $this->app->forgetScopedInstances();

        return app(OrderAdminReader::class);
    }

    private function placementTransactionId(string $clientId): string
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(1000, 'EUR'),
            soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        return $transaction->id();
    }

    private function orderId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $this->placementTransactionId($client->id()),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    /**
     * A real payment row for the order — a new row per attempt
     * (payment-domain-design.md §1: a retry is never an in-place rewrite).
     * attempted_at is passed explicitly: it is the fact the "current payment"
     * rule sorts on, so a test about that rule must own it.
     */
    private function savedPayment(string $orderId, string $method, string $attemptedAt): Payment
    {
        $payment = Payment::create($orderId, $method, Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    /**
     * The void's whole effect on this reader: the row that WAS current stops
     * being it, and the order's obligations fall back to the newest row that is
     * still in force — here the older, untouched one.
     */
    public function test_a_voided_row_is_never_the_current_payment_in_for_order(): void
    {
        $orderId = $this->orderId();

        $older = $this->savedPayment($orderId, 'cash_on_delivery', '2026-09-28 10:00:00');
        $newer = $this->savedPayment($orderId, 'bank_transfer', '2026-09-28 11:00:00');

        $this->assertSame($newer->id(), $this->reader()->forOrder($orderId)->latestPayment?->id());

        $newer->void(new DateTimeImmutable('2026-09-28 11:30:00'));
        app(PaymentRepository::class)->save($newer);

        $view = $this->freshReader()->forOrder($orderId);

        $this->assertNotNull($view->latestPayment);
        $this->assertSame($older->id(), $view->latestPayment->id());
        $this->assertSame('cash_on_delivery', $view->latestPayment->method());
        $this->assertNull($view->latestPayment->voidedAt());
        $this->assertSame(2, count($view->payments), 'every row is still carried in payments, voided rows included');
    }

    /**
     * §7.3's void-plus-reissue, seen from the reader: the called-off row is
     * superseded by a NEW row carrying the remainder — the shape a cancellation
     * that reduces what the customer owes writes — and the reader follows the
     * new row, never the voided one, even though nothing about the old row moved
     * except voided_at.
     */
    public function test_a_reissued_newer_row_is_the_current_payment_while_the_voided_one_is_not(): void
    {
        $orderId = $this->orderId();

        $original = $this->savedPayment($orderId, 'cash_on_delivery', '2026-09-28 10:00:00');
        $original->void(new DateTimeImmutable('2026-09-28 10:30:00'));
        app(PaymentRepository::class)->save($original);

        $reissued = $this->savedPayment($orderId, 'cash_on_delivery', '2026-09-28 10:31:00');

        $view = $this->reader()->forOrder($orderId);

        $this->assertSame($reissued->id(), $view->latestPayment?->id());
        $this->assertSame(2, count($view->payments));

        // A voided row is only ever outranked by a row that IS current: with a
        // newer voided row and no reissue, the OLDER row is current again (the
        // test above), which is what proves the condition rather than the
        // ordering is doing the work.
        $fresh = $this->freshReader()->forOrder($orderId);
        $this->assertNotSame($original->id(), $fresh->latestPayment?->id());
    }

    /**
     * Every row voided: the order has no current payment at all, and the View
     * page renders its own '—' (D8) rather than resurrecting a called-off row.
     * The trail is still fully visible through paymentAttemptCount.
     */
    public function test_an_order_whose_every_payment_is_voided_shows_no_current_payment(): void
    {
        $orderId = $this->orderId();

        $first = $this->savedPayment($orderId, 'cash_on_delivery', '2026-09-28 10:00:00');
        $second = $this->savedPayment($orderId, 'bank_transfer', '2026-09-28 11:00:00');

        $first->void(new DateTimeImmutable('2026-09-28 10:30:00'));
        app(PaymentRepository::class)->save($first);

        $second->void(new DateTimeImmutable('2026-09-28 11:30:00'));
        app(PaymentRepository::class)->save($second);

        $view = $this->freshReader()->forOrder($orderId);

        $this->assertNotNull($view);
        $this->assertNull($view->latestPayment);
        $this->assertSame(2, count($view->payments));
    }

    /**
     * The LIST's half of the same rule (D6): the payment-method filter and its
     * option list must never match on a voided row — the newest row that is not
     * current is not what the order's obligations live on, so it must not be why
     * the order appears under a method filter.
     */
    public function test_a_voided_row_is_never_the_latest_payment_in_the_lists_filter(): void
    {
        $orderId = $this->orderId();

        $this->savedPayment($orderId, 'cash_on_delivery', '2026-09-28 10:00:00');
        $newer = $this->savedPayment($orderId, 'bank_transfer', '2026-09-28 11:00:00');

        $reader = $this->reader();

        // Before the void: the newer row is current, so bank_transfer matches.
        $this->assertSame(['bank_transfer'], $reader->paymentMethodOptions());
        $this->assertSame(1, $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'bank_transfer')->count());

        $newer->void(new DateTimeImmutable('2026-09-28 11:30:00'));
        app(PaymentRepository::class)->save($newer);

        $reader = $this->freshReader();

        // After it: the order is current on cash_on_delivery again, and
        // bank_transfer exists only on a voided row — so it is neither offered
        // nor matched.
        $this->assertSame(['cash_on_delivery'], $reader->paymentMethodOptions());
        $this->assertSame(1, $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'cash_on_delivery')->count());
        $this->assertSame(0, $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'bank_transfer')->count());
    }
}
