<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Filament\StaffPanelUser;
use App\Services\OrderPaymentConfirmer;
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
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §4.3 (its §10 stage 5) —
 * App\Services\OrderPaymentConfirmer::confirm(), the one place a payment's money
 * is recorded as received.
 *
 * WHAT THIS FILE IS REALLY PINNING, beyond the happy path: that the confirmation
 * and its event are one atomic unit (§6.2), that the ORDER row is locked before
 * any payment is read or written (§11 item 13 — asserted from the emitted
 * statements, not from a comment), and that the hook fires exactly once, AFTER
 * the commit, so a listener that throws cannot un-record a fact (§12, §8.3
 * item 4).
 *
 * ORDERS ARE NOT PLACED THROUGH CheckoutOrchestrator HERE, deliberately: this
 * class's own contract is a payment id, and what §4.3 is about is the
 * confirmation, not checkout. The fixture builds a real `orders` row through
 * Order + EloquentOrderRepository and real `payments` rows through the real
 * repository, so every constraint is genuinely in play without dragging carts,
 * pricing lists and stock into a test about a money fact.
 */
class OrderPaymentConfirmerTest extends TestCase
{
    use RefreshDatabase;

    private function confirmer(): OrderPaymentConfirmer
    {
        return app(OrderPaymentConfirmer::class);
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

    /** A real, saved `orders` row — a placed order, exactly as checkout leaves one. */
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
     * The normal offline state this class exists for: a PENDING attempt whose
     * adapter answered, awaiting a merchant — the same shape CheckoutOrchestrator
     * leaves behind (§0 item 7's live check: 6 payments, all pending, none with
     * a NULL attempted_at).
     */
    private function savedAnsweredPending(string $orderId, string $attemptedAt = '2026-09-28 10:00:00'): Payment
    {
        $payment = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function actingAsPanelStaff(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('payment.confirmer@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function paymentRow(string $paymentId): object
    {
        return DB::table('payments')->where('id', $paymentId)->sole();
    }

    private function eventRow(string $orderId): object
    {
        return DB::table('order_events')->where('order_id', $orderId)->sole();
    }

    // --- the happy path (§4.3's steps 1-6) -----------------------------------

    /**
     * The whole operation, in one assertion set: confirmed_at written, status
     * still pending (§4.2), ONE event row with both statuses NULL (§6.1's shape
     * for "a real event, no transition"), the panel actor on it (§6.2's
     * internally-resolved actor), and the ORDER untouched (§3 item 1 — money
     * arriving moves no status).
     */
    public function test_it_records_the_confirmation_and_one_event_and_leaves_the_order_alone(): void
    {
        $orderId = $this->orderId();
        $payment = $this->savedAnsweredPending($orderId);
        $staff = $this->actingAsPanelStaff();

        $this->confirmer()->confirm($payment->id(), new DateTimeImmutable('2026-09-28 12:15:00'));

        $row = $this->paymentRow($payment->id());

        $this->assertSame('2026-09-28 12:15:00', $row->confirmed_at);
        $this->assertSame('pending', $row->status, 'a confirmation never moves the status');
        $this->assertSame('2026-09-28 10:00:00', $row->attempted_at, 'the adapter\'s own answer is untouched');

        $event = $this->eventRow($orderId);

        $this->assertSame(OrderEventType::PAYMENT_CONFIRMED->value, $event->type);
        $this->assertNull($event->from_status);
        $this->assertNull($event->to_status);
        $this->assertNull($event->reason);
        $this->assertNull($event->transaction_id);
        $this->assertSame('2026-09-28 12:15:00', $event->occurred_at);
        $this->assertSame((string) $staff->id, (string) $event->staff_id);
        $this->assertSame('Ana Petrova', $event->staff_name);

        $this->assertSame('placed', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('order_events')->count());
    }

    /**
     * §4.3 step 2 — the courtesy check that produces a readable refusal. The
     * message names the settled attempt, because "an order may hold money once"
     * is useless to an operator without knowing which row holds it.
     *
     * Nothing is written: no confirmation on the refused row, no event.
     */
    public function test_it_refuses_when_the_order_already_has_a_settled_payment(): void
    {
        $orderId = $this->orderId();

        $settled = $this->savedAnsweredPending($orderId, '2026-09-28 10:00:00');
        $settled->confirm(new DateTimeImmutable('2026-09-28 10:30:00'));
        app(PaymentRepository::class)->save($settled);

        $second = $this->savedAnsweredPending($orderId, '2026-09-28 11:00:00');

        try {
            $this->confirmer()->confirm($second->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('A second settled payment for one order must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString((string) $settled->id(), $exception->getMessage());
            $this->assertStringContainsString('already has a settled payment', $exception->getMessage());
            $this->assertStringContainsString('confirmed at', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($second->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    /**
     * A CAPTURED attempt settles the order in the adapter's own half, so the
     * refusal names that fact rather than the confirmation.
     */
    public function test_it_refuses_when_the_order_already_has_a_captured_payment(): void
    {
        $orderId = $this->orderId();

        $captured = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($captured);

        $pending = $this->savedAnsweredPending($orderId, '2026-09-28 11:00:00');

        try {
            $this->confirmer()->confirm($pending->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('An order whose money the adapter captured must not accept a confirmation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString((string) $captured->id(), $exception->getMessage());
            $this->assertStringContainsString('captured by the adapter', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($pending->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    public function test_it_refuses_a_payment_that_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no payment exists with id');

        $this->confirmer()->confirm('999999', new DateTimeImmutable('2026-09-28 12:00:00'));
    }

    /**
     * payments.order_id is a plain string with no FK (payment-domain-design.md
     * §6), so a dangling reference is representable — and refused here rather
     * than locked blindly.
     */
    public function test_it_refuses_a_payment_whose_order_does_not_exist(): void
    {
        $orphan = Payment::create('999999', 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $orphan->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($orphan);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('which does not exist');

        $this->confirmer()->confirm($orphan->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
    }

    // --- the domain's own guards, surfaced unwrapped (§5.2) -------------------

    /**
     * The four states Payment::confirm() refuses, each reached through the
     * SERVICE — so what is proven is that the domain's LogicExceptions travel
     * out of §4.3's transaction unmodified (§5.2's "errors are not wrapped") and
     * that nothing is written on the way out (no confirmation, no event).
     */
    public function test_it_refuses_a_crashed_unanswered_attempt_and_writes_nothing(): void
    {
        $orderId = $this->orderId();

        // PENDING with a NULL attempted_at: Phase 1 committed, Phase 2 never ran.
        $crashed = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        app(PaymentRepository::class)->save($crashed);

        try {
            $this->confirmer()->confirm($crashed->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('A never-answered attempt must not be confirmable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('never answered', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($crashed->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    public function test_it_refuses_a_failed_attempt_and_writes_nothing(): void
    {
        $orderId = $this->orderId();

        $failed = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($failed);

        try {
            $this->confirmer()->confirm($failed->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('A failed attempt must not be confirmable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('there is no money', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($failed->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    /**
     * A CAPTURED row is caught by §4.3 step 2 itself — the scan covers the
     * payment being confirmed as well as its siblings, so the refusal is the
     * service's readable one naming that row. The domain's own CAPTURED guard
     * (§4.1's guard 2) is the second line behind it, and is exercised directly in
     * the Payment package's suite.
     */
    public function test_it_refuses_a_captured_attempt_and_writes_nothing(): void
    {
        $orderId = $this->orderId();

        $captured = Payment::create($orderId, 'card_stripe', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ch_1', null, new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($captured);

        try {
            $this->confirmer()->confirm($captured->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('An attempt the adapter already settled must not be confirmable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString((string) $captured->id(), $exception->getMessage());
            $this->assertStringContainsString('already has a settled payment', $exception->getMessage());
            $this->assertStringContainsString('captured by the adapter', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($captured->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    /**
     * A voided row is NOT settled, so §4.3's courtesy check correctly lets it
     * through — and the domain refuses it, which is the architect's addition to
     * §4.1's guard list: a called-off obligation is not a payment.
     */
    public function test_it_refuses_a_voided_attempt_and_writes_nothing(): void
    {
        $orderId = $this->orderId();
        $voided = $this->savedAnsweredPending($orderId);

        $voided->void(new DateTimeImmutable('2026-09-28 10:30:00'));
        app(PaymentRepository::class)->save($voided);

        try {
            $this->confirmer()->confirm($voided->id(), new DateTimeImmutable('2026-09-28 12:00:00'));
            $this->fail('A voided obligation must not be confirmable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('was voided', $exception->getMessage());
        }

        $this->assertNull($this->paymentRow($voided->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
    }

    // --- one atomic unit, one lock order --------------------------------------

    /**
     * §6.2's "the event is part of the same fact": a confirmation that rolls back
     * leaves behind neither the column nor the event. Rolled back by the CALLER,
     * exactly as §4.3 says `deliver()` will do it later.
     *
     * ONE OBSERVATION PINNED HERE RATHER THAN LEFT TO BE DISCOVERED LATER: the
     * hook fires when THIS class's transaction — a savepoint inside the caller's
     * own — is released, so it has already fired by the time the outer rollback
     * below happens. That is §4.3's documented savepoint composition, and it is
     * the one place §12's "after the transaction commits" is ambiguous about
     * WHICH transaction. The data half is unambiguous, and it is what this test
     * is really about.
     */
    public function test_a_rolled_back_call_leaves_no_confirmation_and_no_event(): void
    {
        $orderId = $this->orderId();
        $payment = $this->savedAnsweredPending($orderId);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        DB::beginTransaction();

        $this->confirmer()->confirm($payment->id(), new DateTimeImmutable('2026-09-28 12:15:00'));

        $this->assertSame(
            '2026-09-28 12:15:00',
            $this->paymentRow($payment->id())->confirmed_at,
            'the confirmation is written inside the caller\'s transaction, not after it'
        );
        $this->assertSame(1, DB::table('order_events')->count());

        DB::rollBack();

        $this->assertNull($this->paymentRow($payment->id())->confirmed_at);
        $this->assertSame(0, DB::table('order_events')->count());
        $this->assertSame(1, $fired, 'the hook fires at the inner savepoint\'s release — see this test\'s docblock');
    }

    /**
     * §11 item 13's one lock order, read off the emitted statements rather than
     * trusted: the ORDER row is read with a real `for update` BEFORE the order's
     * payments are read and before any payment is written.
     *
     * The very first statement is necessarily a payment read by primary key — the
     * order to lock is only known from that row — and it is deliberately NOT a
     * locked read. The lock that orders the operation is the one on the order.
     */
    public function test_the_order_row_is_locked_before_any_payment_is_read_or_written(): void
    {
        $orderId = $this->orderId();
        $payment = $this->savedAnsweredPending($orderId);

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->confirmer()->confirm($payment->id(), new DateTimeImmutable('2026-09-28 12:15:00'));

        $paymentByIdIndex = null;
        $orderLockIndex = null;
        $paymentsOfOrderIndex = null;
        $paymentWriteIndex = null;

        foreach ($statements as $index => $sql) {
            $normalised = strtolower($sql);

            if ($paymentByIdIndex === null
                && str_starts_with($normalised, 'select * from `payments`')
                && str_contains($normalised, '`payments`.`id` = ?')) {
                $paymentByIdIndex = $index;
            }

            if ($orderLockIndex === null
                && str_contains($normalised, 'from `orders`')
                && str_ends_with($normalised, 'for update')) {
                $orderLockIndex = $index;
            }

            if ($paymentsOfOrderIndex === null
                && str_starts_with($normalised, 'select * from `payments`')
                && str_contains($normalised, '`order_id` = ?')) {
                $paymentsOfOrderIndex = $index;
            }

            if ($paymentWriteIndex === null && str_starts_with($normalised, 'update `payments`')) {
                $paymentWriteIndex = $index;
            }
        }

        $this->assertNotNull($paymentByIdIndex, 'the payment is read by id, to discover which order to lock');
        $this->assertNotNull($orderLockIndex, 'the order must be read with FOR UPDATE');
        $this->assertNotNull($paymentsOfOrderIndex, '§4.3 step 2 reads the order\'s payments');
        $this->assertNotNull($paymentWriteIndex, 'the confirmation writes the payment row');

        $this->assertStringNotContainsString('for update', strtolower($statements[$paymentByIdIndex]));
        $this->assertLessThan($paymentsOfOrderIndex, $orderLockIndex, 'the order lock precedes the payments-of-order read');
        $this->assertLessThan($paymentWriteIndex, $orderLockIndex, 'the order lock precedes the payment write');
        $this->assertSame(
            1,
            count(array_filter($statements, static fn (string $sql): bool => str_ends_with(strtolower($sql), 'for update'))),
            'exactly one locked read in the whole operation'
        );
    }

    // --- the hook: after the commit, exactly once -----------------------------

    public function test_the_hook_fires_exactly_once_after_the_commit_with_the_confirmed_payment(): void
    {
        $orderId = $this->orderId();
        $payment = $this->savedAnsweredPending($orderId);

        $fired = 0;
        $received = null;
        $confirmedAtSeenByTheListener = null;

        Hook::action('order.payment_confirmed', function (Payment $confirmed) use (&$fired, &$received, &$confirmedAtSeenByTheListener): void {
            $fired++;
            $received = $confirmed;

            // Read back from the DATABASE, not from the object in hand: that is
            // what "after the commit" means — the fact is durable before any
            // listener runs, so no listener can outrun the write it is told about.
            $confirmedAtSeenByTheListener = DB::table('payments')->where('id', $confirmed->id())->value('confirmed_at');
        });

        $this->confirmer()->confirm($payment->id(), new DateTimeImmutable('2026-09-28 12:15:00'));

        $this->assertSame(1, $fired);
        $this->assertNotNull($received);
        $this->assertSame($payment->id(), $received->id());
        $this->assertSame('2026-09-28 12:15:00', $received->confirmedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 12:15:00', $confirmedAtSeenByTheListener);
    }

    public function test_the_hook_does_not_fire_when_the_call_is_refused(): void
    {
        $orderId = $this->orderId();

        $unanswered = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);
        app(PaymentRepository::class)->save($unanswered);

        $fired = 0;
        Hook::action('order.payment_confirmed', function () use (&$fired): void {
            $fired++;
        });

        try {
            $this->confirmer()->confirm($unanswered->id(), new DateTimeImmutable('2026-09-28 12:15:00'));
            $this->fail('A never-answered attempt must be refused.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(0, $fired);
    }

    /**
     * §8.3 item 4's policy, end to end: the extension layer's failure is the
     * operator's to see, and it does not undo the merchant's fact.
     */
    public function test_a_listener_that_throws_after_the_commit_propagates_while_the_confirmation_stays_recorded(): void
    {
        $orderId = $this->orderId();
        $payment = $this->savedAnsweredPending($orderId);

        Hook::action('order.payment_confirmed', function (): void {
            throw new RuntimeException('the listener exploded');
        });

        try {
            $this->confirmer()->confirm($payment->id(), new DateTimeImmutable('2026-09-28 12:15:00'));
            $this->fail('HookRegistry never swallows a listener exception (extensibility-design-and-hooks.md §4).');
        } catch (RuntimeException $exception) {
            $this->assertSame('the listener exploded', $exception->getMessage());
        }

        $this->assertSame('2026-09-28 12:15:00', $this->paymentRow($payment->id())->confirmed_at);
        $this->assertSame(1, DB::table('order_events')->count());
    }
}
