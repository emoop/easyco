<?php

namespace Tests\Feature;

use App\Services\OrderStatusChanger;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §5.2 (its §10 stage 6a) —
 * App\Services\OrderStatusChanger::confirm()/ship()/deliver().
 * cancel()/recordReturn() are stage 6b's; nothing here exercises them
 * because nothing here declares them.
 *
 * WHAT THIS FILE IS REALLY PINNING, beyond each happy path: that the shared
 * six-step shape (§5.2) refuses every illegal move unwrapped, that an
 * idempotent same-status call writes and fires literally nothing, that R9
 * reads the payment method off Payment — never off Order, which has no such
 * field (see OrderStatusChanger's own docblock) — and, the one regression
 * this stage exists to close, that BOTH order.status_changed and
 * order.payment_confirmed fire strictly outside any open transaction on
 * deliver()'s R10 path, not at OrderPaymentConfirmer's own inner savepoint
 * release.
 */
class OrderStatusChangerTest extends TestCase
{
    use RefreshDatabase;

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
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

    /**
     * A real, saved `orders` row, constructed directly in $status —
     * Order::create()'s own $status parameter, bypassing the transition
     * matrix entirely, exactly the way a fixture is allowed to (the matrix
     * governs Order::confirm()/ship()/deliver(), not construction).
     */
    private function orderId(OrderStatus $status = OrderStatus::PLACED): string
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
            status: $status,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    /** The normal offline state R10 confirms: a PENDING attempt whose adapter answered. */
    private function pendingAnswered(string $orderId, string $method, string $attemptedAt = '2026-09-28 10:00:00'): Payment
    {
        $payment = Payment::create($orderId, $method, Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function eventRows(string $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->get()->all();
    }

    private function orderStatus(string $orderId): string
    {
        return DB::table('orders')->where('id', $orderId)->value('status');
    }

    private function countQueries(\Closure $callback): int
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };
        DB::listen($listener);
        $callback();
        // No public "unlisten" API exists on the Connection; each call to
        // this helper adds one more listener, harmless for a single test
        // method's own sequence of measurements (each closure captures its
        // own $count by reference, so an earlier listener never touches a
        // later measurement's counter).
        return $count;
    }

    // --- the happy paths (§5.2 steps 1-6) -------------------------------------

    public function test_confirm_moves_placed_to_confirmed_and_writes_one_event_and_fires_the_hook(): void
    {
        $orderId = $this->orderId(OrderStatus::PLACED);

        $fired = 0;
        $seen = null;
        Hook::action('order.status_changed', function (Order $order, OrderStatus $from, OrderStatus $to) use (&$fired, &$seen): void {
            $fired++;
            $seen = [$order, $from, $to];
        });

        $this->changer()->confirm($orderId, new DateTimeImmutable('2026-09-28 12:00:00'), 'accepted by phone');

        $this->assertSame('confirmed', $this->orderStatus($orderId));

        $events = $this->eventRows($orderId);
        $this->assertCount(1, $events);
        $this->assertSame('status_changed', $events[0]->type);
        $this->assertSame('placed', $events[0]->from_status);
        $this->assertSame('confirmed', $events[0]->to_status);
        $this->assertSame('accepted by phone', $events[0]->reason);
        $this->assertNull($events[0]->transaction_id);
        $this->assertSame('2026-09-28 12:00:00', $events[0]->occurred_at);

        $this->assertSame(1, $fired);
        $this->assertSame($orderId, $seen[0]->id());
        $this->assertSame(OrderStatus::PLACED, $seen[1]);
        $this->assertSame(OrderStatus::CONFIRMED, $seen[2]);
    }

    public function test_ship_moves_confirmed_to_shipped_on_cash_on_delivery_with_no_settled_payment(): void
    {
        $orderId = $this->orderId(OrderStatus::CONFIRMED);
        $this->pendingAnswered($orderId, 'cash_on_delivery');

        $this->changer()->ship($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));

        $this->assertSame('shipped', $this->orderStatus($orderId));
        $events = $this->eventRows($orderId);
        $this->assertCount(1, $events);
        $this->assertSame('confirmed', $events[0]->from_status);
        $this->assertSame('shipped', $events[0]->to_status);
        $this->assertNull($events[0]->reason, 'a null note is stored as NULL, not an empty string');
    }

    public function test_deliver_moves_shipped_to_delivered_when_there_is_no_confirmable_payment(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertSame(0, $fired, 'no payment row at all is one of §4.5\'s four other states — nothing confirmable');
        $events = $this->eventRows($orderId);
        $this->assertCount(1, $events);
        $this->assertSame('status_changed', $events[0]->type);
    }

    // --- illegal moves, refused unwrapped, nothing written ---------------------

    /** @return list<OrderStatus> every status other than $legalFrom and $target (idempotence is tested separately) */
    private function otherStatuses(OrderStatus $legalFrom, OrderStatus $target): array
    {
        return array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $s): bool => $s !== $legalFrom && $s !== $target,
        ));
    }

    private function assertRefusedFromEveryOtherStatus(string $method, OrderStatus $legalFrom, OrderStatus $target): void
    {
        foreach ($this->otherStatuses($legalFrom, $target) as $from) {
            $orderId = $this->orderId($from);

            $fired = 0;
            Hook::action('order.status_changed', function () use (&$fired): void {
                $fired++;
            });

            try {
                $this->changer()->{$method}($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));
                $this->fail("{$method}() from \"{$from->value}\" must be refused.");
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertSame($from, $exception->from());
                $this->assertSame($target, $exception->to());
            }

            $this->assertSame($from->value, $this->orderStatus($orderId), "orders.status must be untouched for a refused {$method}() from \"{$from->value}\".");
            $this->assertSame(0, DB::table('order_events')->where('order_id', $orderId)->count());
            $this->assertSame(0, $fired);
        }
    }

    public function test_confirm_is_refused_from_every_status_but_placed(): void
    {
        $this->assertRefusedFromEveryOtherStatus('confirm', OrderStatus::PLACED, OrderStatus::CONFIRMED);
    }

    public function test_ship_is_refused_from_every_status_but_confirmed(): void
    {
        $this->assertRefusedFromEveryOtherStatus('ship', OrderStatus::CONFIRMED, OrderStatus::SHIPPED);
    }

    public function test_deliver_is_refused_from_every_status_but_shipped(): void
    {
        $this->assertRefusedFromEveryOtherStatus('deliver', OrderStatus::SHIPPED, OrderStatus::DELIVERED);
    }

    // --- idempotence: a same-status call writes and fires nothing --------------

    private function assertIdempotentNoOp(string $method, OrderStatus $target): void
    {
        $orderId = $this->orderId($target);

        $fired = 0;
        Hook::action('order.status_changed', function () use (&$fired): void {
            $fired++;
        });

        $count = $this->countQueries(function () use ($method, $orderId): void {
            $this->changer()->{$method}($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));
        });

        $this->assertSame($target->value, $this->orderStatus($orderId));
        $this->assertSame(0, DB::table('order_events')->where('order_id', $orderId)->count());
        $this->assertSame(0, $fired);
        $this->assertSame(1, $count, 'the idempotent path costs exactly the one locked read — no save, no event, no further query');
    }

    public function test_confirm_is_a_silent_no_op_when_already_confirmed(): void
    {
        $this->assertIdempotentNoOp('confirm', OrderStatus::CONFIRMED);
    }

    public function test_ship_is_a_silent_no_op_when_already_shipped(): void
    {
        $this->assertIdempotentNoOp('ship', OrderStatus::SHIPPED);
    }

    public function test_deliver_is_a_silent_no_op_when_already_delivered(): void
    {
        $this->assertIdempotentNoOp('deliver', OrderStatus::DELIVERED);
    }

    // --- validation before any lock ---------------------------------------------

    public function test_each_method_refuses_an_empty_order_id_before_any_query(): void
    {
        foreach (['confirm', 'ship', 'deliver'] as $method) {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });

            try {
                $this->changer()->{$method}('', new DateTimeImmutable('2026-09-28 12:00:00'));
                $this->fail("{$method}('') must be refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('orderId must not be empty', $exception->getMessage());
            }

            $this->assertSame(0, $count, "{$method}('') must not issue a single query.");
        }
    }

    public function test_each_method_refuses_an_unknown_order_id(): void
    {
        foreach (['confirm', 'ship', 'deliver'] as $method) {
            try {
                $this->changer()->{$method}('does-not-exist', new DateTimeImmutable('2026-09-28 12:00:00'));
                $this->fail("{$method}('does-not-exist') must be refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('no order exists with id', $exception->getMessage());
            }
        }

        // Zero writes landed anywhere, however the read that discovered the
        // missing order was framed (§5.2 step 2 opens a transaction to run
        // findByIdForUpdate(); a missing row makes that transaction roll
        // back with nothing to undo).
        $this->assertSame(0, DB::table('order_events')->count());
    }

    // --- R9: ship() refused while a bank transfer has not settled ---------------

    public function test_ship_refuses_a_bank_transfer_order_with_no_settled_payment(): void
    {
        $orderId = $this->orderId(OrderStatus::CONFIRMED);
        $this->pendingAnswered($orderId, 'bank_transfer');

        $fired = 0;
        Hook::action('order.status_changed', function () use (&$fired): void {
            $fired++;
        });

        try {
            $this->changer()->ship($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('R9 must refuse ship() while the bank transfer has not settled.');
        } catch (\App\Services\Exceptions\OrderTransitionRefusedException $exception) {
            $this->assertSame(\App\Enums\OrderRefusalReason::BANK_TRANSFER_NOT_SETTLED, $exception->reason());
        }

        $this->assertSame('confirmed', $this->orderStatus($orderId));
        $this->assertSame(0, DB::table('order_events')->where('order_id', $orderId)->count());
        $this->assertSame(0, $fired);
    }

    public function test_ship_succeeds_once_a_bank_transfer_payment_has_settled(): void
    {
        $orderId = $this->orderId(OrderStatus::CONFIRMED);
        $payment = $this->pendingAnswered($orderId, 'bank_transfer');
        $payment->confirm(new DateTimeImmutable('2026-09-28 11:00:00'));
        app(PaymentRepository::class)->save($payment);

        $this->changer()->ship($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));

        $this->assertSame('shipped', $this->orderStatus($orderId));
    }

    public function test_ship_succeeds_unguarded_on_cash_on_delivery_with_no_settled_payment_at_all(): void
    {
        $orderId = $this->orderId(OrderStatus::CONFIRMED);
        $this->pendingAnswered($orderId, 'cash_on_delivery');

        $this->changer()->ship($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));

        $this->assertSame('shipped', $this->orderStatus($orderId));
    }

    public function test_the_bank_transfer_refusal_reason_has_a_non_blank_label_in_every_language(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $key = 'orders.refusal_reasons.'.\App\Enums\OrderRefusalReason::BANK_TRANSFER_NOT_SETTLED->value;

            $this->assertTrue(Lang::has($key, $locale, false), "lang/{$locale}/orders.php must carry a refusal_reasons.bank_transfer_not_settled entry.");

            $label = Lang::get($key, [], $locale, false);
            $this->assertIsString($label);
            $this->assertNotSame('', trim($label));
        }
    }

    // --- R10: deliver()'s payment confirmation, and the timing fix this stage exists for ---

    /**
     * The important one: both writes land inside deliver()'s own single
     * call, and — the actual regression this stage closes — both hooks fire
     * strictly OUTSIDE any transaction deliver() itself opened, proven via
     * DB::transactionLevel() read from inside each listener rather than by
     * re-reading a value on the same connection (which would see its own
     * uncommitted writes regardless of whether the surrounding transaction
     * ever truly commits — exactly what OrderPaymentConfirmerTest's own
     * test_a_rolled_back_call_leaves_no_confirmation_and_no_event already
     * demonstrates for the savepoint case this stage avoids).
     *
     * THE BASELINE, NOT A LITERAL ZERO: RefreshDatabase itself wraps every
     * test method in its own outer transaction (rolled back after the
     * test), so DB::transactionLevel() is never really 0 inside a test —
     * asserting a literal 0 here was this test's own first draft, and it
     * failed for exactly that reason, not because of a production bug. What
     * actually matters, and what is asserted instead, is that the level at
     * each hook is back down to whatever it was BEFORE deliver() was
     * called — proving OrderStatusChanger's own transaction (and
     * confirmWithinOpenTransaction()'s nested re-lock inside it) is fully
     * closed by the time the hook runs, not still open one level deeper.
     */
    public function test_deliver_confirms_a_cash_on_delivery_payment_in_one_transaction_and_both_hooks_fire_after_the_real_commit(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);
        $payment = $this->pendingAnswered($orderId, 'cash_on_delivery');

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $baselineLevel = DB::transactionLevel();

        $statusFired = 0;
        $paymentFired = 0;
        $levelAtStatusChanged = null;
        $levelAtPaymentConfirmed = null;

        Hook::action('order.status_changed', function () use (&$statusFired, &$levelAtStatusChanged): void {
            $statusFired++;
            $levelAtStatusChanged = DB::transactionLevel();
        });

        Hook::action('order.payment_confirmed', function () use (&$paymentFired, &$levelAtPaymentConfirmed): void {
            $paymentFired++;
            $levelAtPaymentConfirmed = DB::transactionLevel();
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame(1, $statusFired);
        $this->assertSame(1, $paymentFired);
        $this->assertSame($baselineLevel, $levelAtStatusChanged, 'order.status_changed must fire with OrderStatusChanger\'s own transaction fully closed — proof of the real commit, back to the level before deliver() was called');
        $this->assertSame($baselineLevel, $levelAtPaymentConfirmed, 'order.payment_confirmed must fire at that same baseline level too — the regression this stage closes');

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertSame('2026-09-28 14:00:00', DB::table('payments')->where('id', $payment->id())->value('confirmed_at'));
        $this->assertSame('pending', DB::table('payments')->where('id', $payment->id())->value('status'), 'confirmation never moves the adapter\'s own status (§4.2)');

        $events = $this->eventRows($orderId);
        $this->assertCount(2, $events);
        $this->assertSame('status_changed', $events[0]->type);
        $this->assertSame('shipped', $events[0]->from_status);
        $this->assertSame('delivered', $events[0]->to_status);
        $this->assertSame('2026-09-28 14:00:00', $events[0]->occurred_at);
        $this->assertSame('payment_confirmed', $events[1]->type);
        $this->assertNull($events[1]->from_status);
        $this->assertNull($events[1]->to_status);
        $this->assertSame('2026-09-28 14:00:00', $events[1]->occurred_at);

        $ordersUpdateIndex = null;
        $paymentsUpdateIndex = null;
        foreach ($statements as $index => $sql) {
            $normalised = strtolower($sql);
            if ($ordersUpdateIndex === null && str_starts_with($normalised, 'update `orders`')) {
                $ordersUpdateIndex = $index;
            }
            if ($paymentsUpdateIndex === null && str_starts_with($normalised, 'update `payments`')) {
                $paymentsUpdateIndex = $index;
            }
        }
        $this->assertNotNull($ordersUpdateIndex, 'the status write must happen inside this single deliver() call');
        $this->assertNotNull($paymentsUpdateIndex, 'the payment confirmation must happen inside this same call');
        $this->assertLessThan($paymentsUpdateIndex, $ordersUpdateIndex, 'the status write precedes R10\'s confirmation, per §5.2\'s own step order');
    }

    public function test_deliver_transitions_without_confirming_when_the_payment_is_already_settled(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);
        $captured = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($captured);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertNull(DB::table('payments')->where('id', $captured->id())->value('confirmed_at'));
        $this->assertSame(0, $fired);
        $this->assertCount(1, $this->eventRows($orderId), 'only STATUS_CHANGED — nothing to confirm');
    }

    public function test_deliver_transitions_without_confirming_when_the_payment_was_never_answered(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);
        $crashed = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        app(PaymentRepository::class)->save($crashed);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertNull(DB::table('payments')->where('id', $crashed->id())->value('confirmed_at'));
        $this->assertSame(0, $fired);
    }

    public function test_deliver_transitions_without_confirming_when_the_payment_failed(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);
        $failed = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($failed);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertNull(DB::table('payments')->where('id', $failed->id())->value('confirmed_at'));
        $this->assertSame(0, $fired);
    }

    public function test_deliver_transitions_without_confirming_when_the_order_has_no_payment_row_at_all(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, $fired);
    }

    /**
     * A gap in §4.5's own table, found and closed rather than silently
     * worked around — see OrderStatusChanger::confirmDeliveryPaymentIfEligible()'s
     * own docblock. Without the isVoided() exclusion this test would fail
     * with an uncaught LogicException aborting the whole delivery.
     */
    public function test_deliver_transitions_without_confirming_when_the_payment_was_voided(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);
        $voided = $this->pendingAnswered($orderId, 'cash_on_delivery');
        $voided->void(new DateTimeImmutable('2026-09-28 11:00:00'));
        app(PaymentRepository::class)->save($voided);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('delivered', $this->orderStatus($orderId));
        $this->assertNull(DB::table('payments')->where('id', $voided->id())->value('confirmed_at'));
        $this->assertSame(0, $fired);
    }

    /**
     * "Inject a failure after the domain call" via a REAL code path rather
     * than a test double: a genuine data anomaly (flagged, not defended
     * against further, in confirmDeliveryPaymentIfEligible()'s own
     * docblock) — a settled payment coexisting with an otherwise-eligible
     * PENDING one — makes confirmWithinOpenTransaction()'s own courtesy
     * check throw AFTER Order::deliver() + its save + the STATUS_CHANGED
     * event have already run inside the SAME transaction. Proves the whole
     * operation rolls back together and neither hook fires.
     */
    public function test_when_r10_itself_fails_after_the_domain_call_the_whole_transition_rolls_back_and_fires_no_hook(): void
    {
        $orderId = $this->orderId(OrderStatus::SHIPPED);

        $settled = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $settled->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($settled);

        $eligible = $this->pendingAnswered($orderId, 'cash_on_delivery', '2026-09-28 10:00:00');

        $statusFired = 0;
        $paymentFired = 0;
        Hook::action('order.status_changed', function () use (&$statusFired): void {
            $statusFired++;
        });
        Hook::action('order.payment_confirmed', function () use (&$paymentFired): void {
            $paymentFired++;
        });

        try {
            $this->changer()->deliver($orderId, new DateTimeImmutable('2026-09-28 14:00:00'));
            $this->fail('the settled+eligible anomaly must surface loudly, not silently confirm.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already has a settled payment', $exception->getMessage());
        }

        $this->assertSame('shipped', $this->orderStatus($orderId), 'the status write must roll back with everything else in the same transaction');
        $this->assertNull(DB::table('payments')->where('id', $eligible->id())->value('confirmed_at'));
        $this->assertSame(0, DB::table('order_events')->where('order_id', $orderId)->count(), 'the STATUS_CHANGED event written earlier in the same transaction must roll back too');
        $this->assertSame(0, $statusFired);
        $this->assertSame(0, $paymentFired);
    }

    // --- T5: bounded query counts ------------------------------------------------

    public function test_query_counts_are_bounded_for_every_path(): void
    {
        $confirmOrder = $this->orderId(OrderStatus::PLACED);
        $confirmCount = $this->countQueries(fn () => $this->changer()->confirm($confirmOrder, new DateTimeImmutable('2026-09-28 12:00:00')));

        $shipUnguardedOrder = $this->orderId(OrderStatus::CONFIRMED);
        $this->pendingAnswered($shipUnguardedOrder, 'cash_on_delivery');
        $shipUnguardedCount = $this->countQueries(fn () => $this->changer()->ship($shipUnguardedOrder, new DateTimeImmutable('2026-09-28 12:00:00')));

        $shipGuardedOrder = $this->orderId(OrderStatus::CONFIRMED);
        $bankPayment = $this->pendingAnswered($shipGuardedOrder, 'bank_transfer');
        $bankPayment->confirm(new DateTimeImmutable('2026-09-28 11:00:00'));
        app(PaymentRepository::class)->save($bankPayment);
        $shipGuardedCount = $this->countQueries(fn () => $this->changer()->ship($shipGuardedOrder, new DateTimeImmutable('2026-09-28 12:00:00')));

        $deliverConfirmsOrder = $this->orderId(OrderStatus::SHIPPED);
        $this->pendingAnswered($deliverConfirmsOrder, 'cash_on_delivery');
        $deliverConfirmsCount = $this->countQueries(fn () => $this->changer()->deliver($deliverConfirmsOrder, new DateTimeImmutable('2026-09-28 14:00:00')));

        $deliverSkipsOrder = $this->orderId(OrderStatus::SHIPPED);
        $deliverSkipsCount = $this->countQueries(fn () => $this->changer()->deliver($deliverSkipsOrder, new DateTimeImmutable('2026-09-28 14:00:00')));

        fwrite(STDERR, "\n[query-count] OrderStatusChanger — confirm(): {$confirmCount} queries\n");
        fwrite(STDERR, "[query-count] OrderStatusChanger — ship() unguarded (cash_on_delivery): {$shipUnguardedCount} queries\n");
        fwrite(STDERR, "[query-count] OrderStatusChanger — ship() guarded (bank_transfer, settled): {$shipGuardedCount} queries\n");
        fwrite(STDERR, "[query-count] OrderStatusChanger — deliver(), R10 confirms: {$deliverConfirmsCount} queries\n");
        fwrite(STDERR, "[query-count] OrderStatusChanger — deliver(), R10 skips (no payment row): {$deliverSkipsCount} queries\n");

        // Bounded, not exact — a real number is reported above for review;
        // these assertions only guard against an accidental N+1 regression.
        $this->assertLessThanOrEqual(4, $confirmCount);
        $this->assertLessThanOrEqual(6, $shipUnguardedCount);
        $this->assertLessThanOrEqual(6, $shipGuardedCount);
        $this->assertLessThanOrEqual(15, $deliverConfirmsCount);
        $this->assertLessThanOrEqual(6, $deliverSkipsCount);
    }
}
