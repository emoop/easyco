<?php

namespace Tests\Feature;

use App\Enums\OrderRefusalReason;
use App\Services\Exceptions\OrderTransitionRefusedException;
use App\Services\OrderStatusChanger;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
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
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
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

    // =========================================================================
    // §10 stage 6b-ii part 2 — cancel()/recordReturn() and the three
    // return-related hooks (order.returned/order.cancelled/order.refunded).
    // =========================================================================

    private static int $returnProductCounter = 0;

    /** stock_levels.variation_id is a real integer FK — every fixture line below targets a real Catalog variation (ReturnGoodsRecorderTest's own fixture note applies here too). */
    private function newVariationId(): string
    {
        self::$returnProductCounter++;
        $suffix = (string) self::$returnProductCounter;

        $product = Product::createSimple("Return Product {$suffix}", "RET-SKU-{$suffix}", "order-status-changer-return-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function stock(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    /**
     * A real order with a REAL placement Transaction/SaleLine per given
     * line — unlike this file's own orderId()/placementTransactionId()
     * above (a single fake 'variation-1' priceableId, fine for stage 6a's
     * own tests, which never restock), every line here targets a real
     * Catalog variation so ReturnGoodsRecorder's restock can actually
     * write to stock_levels.
     *
     * @param list<array{variationId: string, quantity: int, unitPriceMinor: int}> $lines
     * @return array{orderId: string, clientId: string, transaction: Transaction, saleLineIds: list<string>}
     */
    private function returnableOrder(OrderStatus $status, array $lines, ?string $appliedPromotionCode = null): array
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        foreach ($lines as $line) {
            $transaction->addSaleLine(SaleLine::create(
                transactionId: '',
                clientId: $client->id(),
                priceableId: $line['variationId'],
                status: SaleLineStatus::COMPLETED,
                quantity: $line['quantity'],
                amount: Money::fromMinorUnits($line['unitPriceMinor'] * $line['quantity'], 'EUR'),
                profit: Money::fromMinorUnits(200 * $line['quantity'], 'EUR'),
                recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                productName: 'Product One',
                sku: 'SKU-1',
                regularUnitPrice: Money::fromMinorUnits($line['unitPriceMinor'], 'EUR'),
                finalUnitPrice: Money::fromMinorUnits($line['unitPriceMinor'], 'EUR'),
                promotionDiscountShare: Money::zero('EUR'),
                discretionaryDiscount: Money::zero('EUR'),
                netPaidAmount: Money::fromMinorUnits($line['unitPriceMinor'] * $line['quantity'], 'EUR'),
                soldAttributes: [],
            ));
        }
        app(TransactionRepository::class)->save($transaction);

        $subtotalMinor = array_sum(array_map(
            static fn (array $l): int => $l['unitPriceMinor'] * $l['quantity'],
            $lines,
        ));

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits($subtotalMinor, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            status: $status,
            appliedPromotionCode: $appliedPromotionCode,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return [
            'orderId' => $order->id(),
            'clientId' => $client->id(),
            'transaction' => $transaction,
            'saleLineIds' => array_map(static fn (SaleLine $l): string => $l->id(), $transaction->saleLines()),
        ];
    }

    private function returnSettledPayment(string $orderId, int $amountMinor, string $method = 'cash_on_delivery'): Payment
    {
        $payment = Payment::create($orderId, $method, Money::fromMinorUnits($amountMinor, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        $payment->confirm(new DateTimeImmutable('2026-09-28 09:30:00'));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function promotionRedemptionId(string $orderId): string
    {
        $promotion = Promotion::create('summer20', PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);
        app(PromotionRepository::class)->save($promotion);

        $redemption = new PromotionRedemption(
            id: null,
            promotionId: $promotion->id(),
            orderId: $orderId,
            accountId: null,
            redeemedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
        );
        app(PromotionRedemptionRepository::class)->save($redemption);

        return $redemption->id();
    }

    private function isRedemptionReleased(string $orderId): bool
    {
        $redemption = app(PromotionRedemptionRepository::class)->findByOrderId($orderId);

        return $redemption !== null && $redemption->isReleased();
    }

    /** Writes a REFUND line directly, bypassing OrderStatusChanger entirely — see this file's own use of it for why that is the only way to construct "already fully returned, but the order's own status was never moved". */
    private function savePriorFullRefund(SaleLine $origin, int $quantityReturned, DateTimeImmutable $at): void
    {
        $refund = SaleLine::createRefund(
            originatingLine: $origin,
            transactionId: '',
            quantityReturned: $quantityReturned,
            defaultRefundAmount: $origin->netPaidAmount(),
            returnedBy: null,
            returnedByName: null,
            returnReason: null,
            displayPriceAtReturn: null,
            recordedAt: $at,
            effectiveAt: $at,
        );

        $priorTransaction = new Transaction(null, Channel::WEB);
        $priorTransaction->addSaleLine($refund);
        app(TransactionRepository::class)->save($priorTransaction);
    }

    // --- cancel(): restocks unconditionally before `shipped`, R11 release, the order.refunded/order.cancelled split ---

    public function test_cancel_from_placed_restocks_unconditionally_fires_returned_and_cancelled_but_not_the_refunded_hook(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);

        $fixture = $this->returnableOrder(
            OrderStatus::PLACED,
            [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]],
            appliedPromotionCode: 'SUMMER20',
        );
        $orderId = $fixture['orderId'];
        $saleLineId = $fixture['saleLineIds'][0];

        $this->promotionRedemptionId($orderId);
        $payment = $this->returnSettledPayment($orderId, 2000);

        $returnedFired = 0;
        $statusChangedFired = 0;
        $cancelledFired = 0;
        $refundedFired = 0;
        Hook::action('order.returned', function () use (&$returnedFired): void { $returnedFired++; });
        Hook::action('order.status_changed', function () use (&$statusChangedFired): void { $statusChangedFired++; });
        Hook::action('order.cancelled', function () use (&$cancelledFired): void { $cancelledFired++; });
        Hook::action('order.refunded', function () use (&$refundedFired): void { $refundedFired++; });

        // The real line is named `false` here, to prove the override map is
        // IGNORED ENTIRELY before `shipped` (R3) — not merely absent.
        $this->changer()->cancel($orderId, new DateTimeImmutable('2026-09-28 12:00:00'), 'changed mind', [$saleLineId => false]);

        $this->assertSame('cancelled', $this->orderStatus($orderId));
        $this->assertSame(12, $this->stock($variationId), 'placed -> cancelled restocks unconditionally, override ignored');

        $events = $this->eventRows($orderId);
        $this->assertCount(3, $events);
        $this->assertSame('returned', $events[0]->type);
        $this->assertSame('status_changed', $events[1]->type);
        $this->assertSame('placed', $events[1]->from_status);
        $this->assertSame('cancelled', $events[1]->to_status);
        $this->assertSame('refunded', $events[2]->type, 'the settled payment WAS refunded — the event ledger records it regardless of which hook fires');

        $this->assertSame(1, $returnedFired);
        $this->assertSame(1, $statusChangedFired);
        $this->assertSame(1, $cancelledFired);
        $this->assertSame(0, $refundedFired, 'order.refunded fires only for a REFUNDED terminal, never for a cancellation — see this stage\'s own report');

        $this->assertTrue($this->isRedemptionReleased($orderId));

        $refunds = app(PaymentRefundRepository::class)->findByPaymentId($payment->id());
        $this->assertCount(1, $refunds);
        $this->assertTrue($refunds[0]->amount()->equals(Money::fromMinorUnits(2000, 'EUR')));
    }

    public function test_cancel_from_shipped_respects_the_per_line_restock_override(): void
    {
        $variationA = $this->newVariationId();
        $variationB = $this->newVariationId();
        $this->setStock($variationA, 10);
        $this->setStock($variationB, 10);

        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [
            ['variationId' => $variationA, 'quantity' => 2, 'unitPriceMinor' => 1000],
            ['variationId' => $variationB, 'quantity' => 3, 'unitPriceMinor' => 500],
        ]);
        $lineA = $fixture['saleLineIds'][0];

        $this->changer()->cancel($fixture['orderId'], new DateTimeImmutable('2026-09-28 12:00:00'), null, [$lineA => false]);

        $this->assertSame('cancelled', $this->orderStatus($fixture['orderId']));
        $this->assertSame(10, $this->stock($variationA), 'restock override false: no stock added');
        $this->assertSame(13, $this->stock($variationB), 'absent from the map: restocks by default');
    }

    // --- recordReturn(): a partial return moves no status; R6's fresh read; the money-event-without-a-hook case ---

    public function test_record_return_partial_from_shipped_moves_no_status_and_fires_only_order_returned(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);

        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [
            ['variationId' => $variationId, 'quantity' => 5, 'unitPriceMinor' => 1000],
        ]);
        $orderId = $fixture['orderId'];
        $saleLineId = $fixture['saleLineIds'][0];

        $payment = $this->returnSettledPayment($orderId, 5000);

        $returnedFired = 0;
        $statusChangedFired = 0;
        $cancelledFired = 0;
        $refundedFired = 0;
        Hook::action('order.returned', function () use (&$returnedFired): void { $returnedFired++; });
        Hook::action('order.status_changed', function () use (&$statusChangedFired): void { $statusChangedFired++; });
        Hook::action('order.cancelled', function () use (&$cancelledFired): void { $cancelledFired++; });
        Hook::action('order.refunded', function () use (&$refundedFired): void { $refundedFired++; });

        $this->changer()->recordReturn($orderId, [
            ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
        ], new DateTimeImmutable('2026-09-28 12:00:00'), 'wrong size');

        $this->assertSame('shipped', $this->orderStatus($orderId), 'a partial return moves no status');
        $this->assertSame(12, $this->stock($variationId));

        $events = $this->eventRows($orderId);
        $this->assertCount(2, $events, 'RETURNED + REFUNDED — a real refund was written even though the order stayed shipped');
        $this->assertSame('returned', $events[0]->type);
        $this->assertSame('refunded', $events[1]->type);

        $this->assertSame(1, $returnedFired);
        $this->assertSame(0, $statusChangedFired);
        $this->assertSame(0, $cancelledFired);
        $this->assertSame(0, $refundedFired, 'no terminal was reached — order.refunded never fires for a partial return, even though a real PaymentRefund was written (see this stage\'s own report)');

        $refunds = app(PaymentRefundRepository::class)->findByPaymentId($payment->id());
        $this->assertCount(1, $refunds);
        $this->assertTrue($refunds[0]->amount()->equals(Money::fromMinorUnits(2000, 'EUR')), 'cumulative share: floor(5000 * 2 / 5) = 2000');
    }

    public function test_record_return_from_delivered_that_empties_the_order_reaches_refunded_and_fires_order_refunded_with_the_real_refund(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);

        $fixture = $this->returnableOrder(
            OrderStatus::DELIVERED,
            [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]],
            appliedPromotionCode: 'SUMMER20',
        );
        $orderId = $fixture['orderId'];
        $saleLineId = $fixture['saleLineIds'][0];
        $this->promotionRedemptionId($orderId);

        $this->returnSettledPayment($orderId, 2000);

        $returnedFired = 0;
        $statusChangedFired = 0;
        $refundedFired = 0;
        $refundedPayload = null;
        Hook::action('order.returned', function () use (&$returnedFired): void { $returnedFired++; });
        Hook::action('order.status_changed', function () use (&$statusChangedFired): void { $statusChangedFired++; });
        Hook::action('order.refunded', function (Order $order, ?PaymentRefund $refund) use (&$refundedFired, &$refundedPayload): void {
            $refundedFired++;
            $refundedPayload = $refund;
        });

        $this->changer()->recordReturn($orderId, [
            ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
        ], new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('refunded', $this->orderStatus($orderId));
        $this->assertSame(1, $returnedFired);
        $this->assertSame(1, $statusChangedFired);
        $this->assertSame(1, $refundedFired);
        $this->assertInstanceOf(PaymentRefund::class, $refundedPayload);
        $this->assertTrue($refundedPayload->amount()->equals(Money::fromMinorUnits(2000, 'EUR')));

        $this->assertFalse($this->isRedemptionReleased($orderId), 'a refund never releases the promotion (R11) — only a cancellation does');
    }

    public function test_record_return_from_delivered_that_empties_the_order_with_nothing_to_refund_still_reaches_refunded_with_a_null_payload(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);

        $fixture = $this->returnableOrder(OrderStatus::DELIVERED, [
            ['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000],
        ]);
        $orderId = $fixture['orderId'];

        $refundedFired = 0;
        $refundedPayload = 'not yet set';
        Hook::action('order.refunded', function (Order $order, ?PaymentRefund $refund) use (&$refundedFired, &$refundedPayload): void {
            $refundedFired++;
            $refundedPayload = $refund;
        });

        // No payment of any kind exists for this order — R8(c): nothing to refund.
        $this->changer()->recordReturn($orderId, [
            ['originatingSaleLineId' => $fixture['saleLineIds'][0], 'quantityReturned' => 2, 'restock' => true],
        ], new DateTimeImmutable('2026-09-28 14:00:00'));

        $this->assertSame('refunded', $this->orderStatus($orderId));
        $this->assertSame(1, $refundedFired);
        $this->assertNull($refundedPayload);

        $events = $this->eventRows($orderId);
        $this->assertCount(2, $events, 'RETURNED + STATUS_CHANGED only — R8(c) writes no money event at all');
    }

    /**
     * The walkthrough this stage's own review gate asked for: the SECOND
     * call must not be fooled by a stale "already returned" figure carried
     * over from the first.
     */
    public function test_two_sequential_record_return_calls_the_second_reads_a_fresh_remaining_and_reaches_the_terminal(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);

        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [
            ['variationId' => $variationId, 'quantity' => 4, 'unitPriceMinor' => 1000],
        ]);
        $orderId = $fixture['orderId'];
        $saleLineId = $fixture['saleLineIds'][0];

        $this->changer()->recordReturn($orderId, [
            ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
        ], new DateTimeImmutable('2026-09-28 12:00:00'));

        $this->assertSame('shipped', $this->orderStatus($orderId), 'first call: 2 of 4 returned, order stays shipped');
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());

        $cancelledFired = 0;
        Hook::action('order.cancelled', function () use (&$cancelledFired): void { $cancelledFired++; });

        $this->changer()->recordReturn($orderId, [
            ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
        ], new DateTimeImmutable('2026-09-28 13:00:00'));

        $this->assertSame('cancelled', $this->orderStatus($orderId), 'second call empties the line: R6\'s fresh read under the lock sees BOTH refund rows (the first call\'s and its own), not a figure cached from before');
        $this->assertSame(1, $cancelledFired);
        $this->assertSame(2, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(14, $this->stock($variationId), 'both calls restocked: 10 + 2 + 2');
    }

    // --- refusals: the legal-status guard neither Order's own matrix nor ReturnGoodsRecorder can enforce ---

    public function test_cancel_is_refused_from_delivered_cancelled_and_refunded(): void
    {
        foreach ([OrderStatus::DELIVERED, OrderStatus::CANCELLED, OrderStatus::REFUNDED] as $status) {
            $variationId = $this->newVariationId();
            $this->setStock($variationId, 10);
            $fixture = $this->returnableOrder($status, [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]]);

            $fired = 0;
            Hook::action('order.cancelled', function () use (&$fired): void { $fired++; });

            try {
                $this->changer()->cancel($fixture['orderId'], new DateTimeImmutable('2026-09-28 12:00:00'));
                $this->fail("cancel() from \"{$status->value}\" must be refused.");
            } catch (OrderTransitionRefusedException $exception) {
                $this->assertSame(OrderRefusalReason::ORDER_NOT_CANCELLABLE, $exception->reason());
            }

            $this->assertSame($status->value, $this->orderStatus($fixture['orderId']));
            $this->assertSame(10, $this->stock($variationId));
            $this->assertSame(0, DB::table('order_events')->where('order_id', $fixture['orderId'])->count());
            $this->assertSame(0, $fired);
        }
    }

    public function test_record_return_is_refused_from_placed_and_confirmed(): void
    {
        foreach ([OrderStatus::PLACED, OrderStatus::CONFIRMED] as $status) {
            $variationId = $this->newVariationId();
            $this->setStock($variationId, 10);
            $fixture = $this->returnableOrder($status, [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]]);

            try {
                $this->changer()->recordReturn($fixture['orderId'], [
                    ['originatingSaleLineId' => $fixture['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true],
                ], new DateTimeImmutable('2026-09-28 12:00:00'));
                $this->fail("recordReturn() from \"{$status->value}\" must be refused.");
            } catch (OrderTransitionRefusedException $exception) {
                $this->assertSame(OrderRefusalReason::ORDER_NOT_RETURNABLE, $exception->reason());
            }

            $this->assertSame($status->value, $this->orderStatus($fixture['orderId']));
            $this->assertSame(10, $this->stock($variationId));
            $this->assertSame(0, DB::table('order_events')->where('order_id', $fixture['orderId'])->count());
        }
    }

    public function test_the_two_new_refusal_reasons_have_a_non_blank_label_in_every_language(): void
    {
        foreach ([OrderRefusalReason::ORDER_NOT_CANCELLABLE, OrderRefusalReason::ORDER_NOT_RETURNABLE] as $reason) {
            foreach (['en', 'bg'] as $locale) {
                $key = 'orders.refusal_reasons.'.$reason->value;

                $this->assertTrue(Lang::has($key, $locale, false), "lang/{$locale}/orders.php must carry a refusal_reasons.{$reason->value} entry.");

                $label = Lang::get($key, [], $locale, false);
                $this->assertIsString($label);
                $this->assertNotSame('', trim($label));
            }
        }
    }

    public function test_record_return_refuses_an_unknown_originating_sale_line_id_before_anything_is_written(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);
        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]]);

        try {
            $this->changer()->recordReturn($fixture['orderId'], [
                ['originatingSaleLineId' => 'does-not-exist', 'quantityReturned' => 1, 'restock' => true],
            ], new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('an unknown originatingSaleLineId must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does-not-exist', $exception->getMessage());
        }

        $this->assertSame('shipped', $this->orderStatus($fixture['orderId']));
        $this->assertSame(10, $this->stock($variationId));
        $this->assertSame(0, DB::table('order_events')->where('order_id', $fixture['orderId'])->count());
    }

    /**
     * NOT DEFENDED AGAINST BY ReturnGoodsRecorder ITSELF (see that class's
     * own docblock, and OrderStatusChanger::recordReturn()'s own "NOT
     * DEFENDED AGAINST" note) — recordReturn()'s own $lines shape reopens
     * exactly the gap ReturnGoodsRecorder's docblock assumed structurally
     * impossible, since it is a plain list rather than a map keyed by id.
     * Found while implementing this stage, guarded proactively rather than
     * left as a live gap — see this stage's own report.
     */
    public function test_record_return_refuses_duplicate_originating_sale_line_ids_in_one_call(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);
        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [['variationId' => $variationId, 'quantity' => 5, 'unitPriceMinor' => 1000]]);
        $saleLineId = $fixture['saleLineIds'][0];

        try {
            $this->changer()->recordReturn($fixture['orderId'], [
                ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
                ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 2, 'restock' => true],
            ], new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('a duplicate originatingSaleLineId must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($saleLineId, $exception->getMessage());
        }

        $this->assertSame(0, DB::table('order_events')->count());
        $this->assertSame(10, $this->stock($variationId));
    }

    public function test_record_return_refuses_a_non_positive_quantity_before_any_query(): void
    {
        $variationId = $this->newVariationId();
        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [['variationId' => $variationId, 'quantity' => 5, 'unitPriceMinor' => 1000]]);

        $count = 0;
        DB::listen(function () use (&$count): void { $count++; });

        try {
            $this->changer()->recordReturn($fixture['orderId'], [
                ['originatingSaleLineId' => $fixture['saleLineIds'][0], 'quantityReturned' => 0, 'restock' => true],
            ], new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('a zero quantityReturned must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('positive integer', $exception->getMessage());
        }

        $this->assertSame(0, $count, 'refused before any query — validated before the lock');
    }

    public function test_record_return_refuses_an_empty_lines_array_before_any_query(): void
    {
        $count = 0;
        DB::listen(function () use (&$count): void { $count++; });

        try {
            $this->changer()->recordReturn('some-order', [], new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('an empty lines array must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not be empty', $exception->getMessage());
        }

        $this->assertSame(0, $count);
    }

    // --- step 4 skipped: every line already fully returned ---------------------

    public function test_cancel_of_an_order_whose_every_line_is_already_fully_returned_skips_step_4_but_still_reaches_the_terminal(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);
        $fixture = $this->returnableOrder(
            OrderStatus::SHIPPED,
            [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]],
            appliedPromotionCode: 'SUMMER20',
        );
        $orderId = $fixture['orderId'];
        $this->promotionRedemptionId($orderId);

        // Simulate "already fully returned via a prior operation" WITHOUT
        // going through OrderStatusChanger — cancel()/recordReturn()
        // themselves always reach the terminal the instant they empty the
        // last line, so this is the only way to construct a still-shipped
        // order whose only line already has zero remaining.
        $this->savePriorFullRefund($fixture['transaction']->saleLines()[0], 2, new DateTimeImmutable('2026-09-28 10:00:00'));

        $returnedFired = 0;
        Hook::action('order.returned', function () use (&$returnedFired): void { $returnedFired++; });

        $this->changer()->cancel($orderId, new DateTimeImmutable('2026-09-28 12:00:00'), 'already empty');

        $this->assertSame('cancelled', $this->orderStatus($orderId));
        $this->assertSame(0, $returnedFired, 'step 4 was skipped — this call returned nothing new');
        $this->assertSame(10, $this->stock($variationId), 'no NEW restock from this call — the prior refund never asked for one');

        $events = $this->eventRows($orderId);
        $this->assertCount(1, $events, 'only STATUS_CHANGED — no RETURNED row for this call');
        $this->assertSame('status_changed', $events[0]->type);

        $this->assertTrue($this->isRedemptionReleased($orderId));
    }

    // --- rollback: OrderRefunder's own anomaly guard, mid-transaction ----------

    public function test_when_order_refunder_itself_fails_the_whole_return_rolls_back_together(): void
    {
        $variationId = $this->newVariationId();
        $this->setStock($variationId, 10);
        $fixture = $this->returnableOrder(OrderStatus::SHIPPED, [['variationId' => $variationId, 'quantity' => 2, 'unitPriceMinor' => 1000]]);
        $orderId = $fixture['orderId'];

        // The same real anomaly stage 6a's own rollback test uses: a
        // settled payment coexisting with a separate pending-eligible one
        // makes OrderRefunder's own guard throw, AFTER ReturnGoodsRecorder
        // has already written the REFUND line, the restock and the
        // RETURNED event in this SAME transaction.
        $this->returnSettledPayment($orderId, 2000);
        $pending = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits(2000, 'EUR'), PaymentStatus::PENDING);
        $pending->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($pending);

        $fired = 0;
        Hook::action('order.returned', function () use (&$fired): void { $fired++; });

        try {
            $this->changer()->cancel($orderId, new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('the settled+pending anomaly must surface loudly.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('settled payment and a separate pending payment', $exception->getMessage());
        }

        $this->assertSame('shipped', $this->orderStatus($orderId));
        $this->assertSame(10, $this->stock($variationId), 'the restock must roll back too');
        $this->assertSame(0, DB::table('order_events')->where('order_id', $orderId)->count());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'the REFUND line must roll back too');
        $this->assertSame(0, $fired);
    }

    // --- T1's own query-count ask ------------------------------------------------

    public function test_query_counts_are_bounded_for_a_single_line_full_cancel_and_a_single_line_partial_return(): void
    {
        $variationId1 = $this->newVariationId();
        $this->setStock($variationId1, 10);
        $cancelFixture = $this->returnableOrder(OrderStatus::PLACED, [['variationId' => $variationId1, 'quantity' => 2, 'unitPriceMinor' => 1000]]);
        $cancelCount = $this->countQueries(fn () => $this->changer()->cancel($cancelFixture['orderId'], new DateTimeImmutable('2026-09-28 12:00:00')));

        $variationId2 = $this->newVariationId();
        $this->setStock($variationId2, 10);
        $returnFixture = $this->returnableOrder(OrderStatus::SHIPPED, [['variationId' => $variationId2, 'quantity' => 5, 'unitPriceMinor' => 1000]]);
        $returnCount = $this->countQueries(fn () => $this->changer()->recordReturn(
            $returnFixture['orderId'],
            [['originatingSaleLineId' => $returnFixture['saleLineIds'][0], 'quantityReturned' => 2, 'restock' => true]],
            new DateTimeImmutable('2026-09-28 12:00:00'),
        ));

        fwrite(STDERR, "\n[query-count] OrderStatusChanger — cancel(), single-line full, placed: {$cancelCount} queries\n");
        fwrite(STDERR, "[query-count] OrderStatusChanger — recordReturn(), single-line partial, shipped: {$returnCount} queries\n");

        // Bounded, not exact — a real number is reported above for review.
        $this->assertLessThanOrEqual(20, $cancelCount);
        $this->assertLessThanOrEqual(15, $returnCount);
    }
}
