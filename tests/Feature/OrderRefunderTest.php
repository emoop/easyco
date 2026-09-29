<?php

namespace Tests\Feature;

use App\Filament\StaffPanelUser;
use App\Services\OrderRefundOutcome;
use App\Services\OrderRefunder;
use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §7.3 (its own stage 6b-ii, part 1) —
 * App\Services\OrderRefunder::refund(). Every call here wraps the
 * refunder in the TEST's OWN DB::transaction(), simulating the future
 * caller (OrderStatusChanger, not yet wired in this pass) that will hold
 * the order's own row lock around this call — this class assumes it is
 * already inside one and never opens its own (see its own docblock).
 *
 * payments.order_id has no real FK (payment-domain-design.md §6) — a
 * plain string orderId is enough for every fixture here; OrderRefunder
 * itself never reads the orders table.
 */
class OrderRefunderTest extends TestCase
{
    use RefreshDatabase;

    private function refunder(): OrderRefunder
    {
        return app(OrderRefunder::class);
    }

    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, 'EUR');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-29 12:00:00');
    }

    private function actingAsPanelStaff(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('refunds.operator@example.com', app(PasswordHasher::class)->hash('password123'), 'Ana Petrova', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** A settled (confirmed) cash-on-delivery payment — real adapter, refund() always COMPLETED. */
    private function savedSettledPayment(string $orderId, int $amountMinor, string $attemptedAt = '2026-09-29 09:00:00', string $confirmedAt = '2026-09-29 09:30:00'): Payment
    {
        $payment = Payment::create($orderId, 'cash_on_delivery', $this->money($amountMinor), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
        $payment->confirm(new DateTimeImmutable($confirmedAt));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    /** A PENDING, answered, unconfirmed, unvoided payment — real adapter, charge() always PENDING. */
    private function savedPendingAnsweredPayment(string $orderId, int $amountMinor, string $method = 'cash_on_delivery', string $attemptedAt = '2026-09-29 09:00:00'): Payment
    {
        $payment = Payment::create($orderId, $method, $this->money($amountMinor), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    private function paymentsForOrder(string $orderId): array
    {
        return app(PaymentRepository::class)->findByOrderId($orderId);
    }

    // --- branch (a): one settled payment ----------------------------------------

    public function test_branch_a_a_full_refund_creates_one_completed_payment_refund(): void
    {
        $orderId = 'order-1';
        $settled = $this->savedSettledPayment($orderId, 1000);
        $staff = $this->actingAsPanelStaff();

        $outcome = DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(1000), $this->now(), 'wrong size'));

        $refunds = app(PaymentRefundRepository::class)->findByPaymentId($settled->id());
        $this->assertCount(1, $refunds);
        $this->assertTrue($refunds[0]->amount()->equals($this->money(1000)));
        $this->assertSame('completed', $refunds[0]->status()->value, 'cash_on_delivery\'s refund() adapter is always COMPLETED');
        $this->assertSame('wrong size', $refunds[0]->reason());
        $this->assertSame((string) $staff->id, $refunds[0]->refundedBy());

        // The outcome — the point of this stage's own addition.
        $this->assertInstanceOf(OrderRefundOutcome::class, $outcome);
        $this->assertTrue($outcome->isRefunded());
        $this->assertFalse($outcome->isVoided());
        $this->assertNotNull($outcome->refund());
        $this->assertSame($refunds[0]->id(), $outcome->refund()->id());
        $this->assertNull($outcome->voidedPayment());
        $this->assertNull($outcome->reissuedPayment());
    }

    public function test_branch_a_a_partial_refund_then_a_second_partial_refund_up_to_the_cap_both_succeed(): void
    {
        $orderId = 'order-1';
        $settled = $this->savedSettledPayment($orderId, 1000);

        DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(600), $this->now(), null));
        DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(400), $this->now(), null));

        $refunds = app(PaymentRefundRepository::class)->findByPaymentId($settled->id());
        $this->assertCount(2, $refunds);
        $this->assertSame(1000, $refunds[0]->amount()->minorValue() + $refunds[1]->amount()->minorValue());
    }

    public function test_branch_a_a_refund_exceeding_the_remaining_cap_is_refused_and_writes_nothing(): void
    {
        $orderId = 'order-1';
        $settled = $this->savedSettledPayment($orderId, 1000);

        DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(600), $this->now(), null));

        try {
            DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(500), $this->now(), null)); // only 400 remains
            $this->fail('a refund exceeding the remaining cap must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('would exceed its remaining refundable amount', $exception->getMessage());
        }

        $refunds = app(PaymentRefundRepository::class)->findByPaymentId($settled->id());
        $this->assertCount(1, $refunds, 'only the first, valid refund exists');
    }

    // --- branch (b): one pending, answered, unvoided, unconfirmed payment -------

    public function test_branch_b_a_partial_refund_voids_the_pending_payment_and_reissues_the_remainder(): void
    {
        $orderId = 'order-1';
        $pending = $this->savedPendingAnsweredPayment($orderId, 1000);

        $outcome = DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(400), $this->now(), null));

        $voidedRow = DB::table('payments')->where('id', $pending->id())->first();
        $this->assertNotNull($voidedRow->voided_at);

        $all = $this->paymentsForOrder($orderId);
        $this->assertCount(2, $all, 'the voided row plus the reissued remainder');

        $reissued = array_values(array_filter($all, fn (Payment $p) => $p->id() !== $pending->id()))[0];
        $this->assertTrue($reissued->amount()->equals($this->money(600)), 'remainder = 1000 - 400');
        $this->assertSame(PaymentStatus::PENDING, $reissued->status(), 'cash_on_delivery\'s charge() adapter is always PENDING');
        $this->assertNotNull($reissued->attemptedAt(), 'the reissued row must set attempted_at (order-lifecycle-design.md §11 item 5)');
        $this->assertFalse($reissued->isVoided());

        // The outcome.
        $this->assertFalse($outcome->isRefunded());
        $this->assertTrue($outcome->isVoided());
        $this->assertNull($outcome->refund());
        $this->assertNotNull($outcome->voidedPayment());
        $this->assertSame($pending->id(), $outcome->voidedPayment()->id());
        $this->assertNotNull($outcome->reissuedPayment());
        $this->assertSame($reissued->id(), $outcome->reissuedPayment()->id());
    }

    public function test_branch_b_a_refund_equal_to_the_full_amount_voids_only_with_no_reissue(): void
    {
        $orderId = 'order-1';
        $pending = $this->savedPendingAnsweredPayment($orderId, 1000);

        $outcome = DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(1000), $this->now(), null));

        $voidedRow = DB::table('payments')->where('id', $pending->id())->first();
        $this->assertNotNull($voidedRow->voided_at);

        $all = $this->paymentsForOrder($orderId);
        $this->assertCount(1, $all, 'no reissue for a zero remainder');

        // The outcome.
        $this->assertFalse($outcome->isRefunded());
        $this->assertTrue($outcome->isVoided());
        $this->assertNotNull($outcome->voidedPayment());
        $this->assertSame($pending->id(), $outcome->voidedPayment()->id());
        $this->assertNull($outcome->reissuedPayment(), 'null for voidedOnly() — a zero remainder reissues nothing');
    }

    public function test_branch_b_a_refund_exceeding_the_pending_amount_is_refused_and_writes_nothing(): void
    {
        $orderId = 'order-1';
        $pending = $this->savedPendingAnsweredPayment($orderId, 1000);

        try {
            DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(1001), $this->now(), null));
            $this->fail('a refund exceeding the pending amount must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('negative remainder', $exception->getMessage());
        }

        $row = DB::table('payments')->where('id', $pending->id())->first();
        $this->assertNull($row->voided_at, 'nothing written — the original row is untouched');
        $this->assertCount(1, $this->paymentsForOrder($orderId));
    }

    // --- branch (c): nothing recorded --------------------------------------------

    public function test_branch_c_no_payment_at_all_writes_nothing_and_does_not_throw(): void
    {
        $orderId = 'order-with-no-payments';

        $outcome = DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(500), $this->now(), null));

        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(0, DB::table('payments')->where('order_id', $orderId)->count());

        // The outcome.
        $this->assertFalse($outcome->isRefunded());
        $this->assertFalse($outcome->isVoided());
        $this->assertNull($outcome->refund());
        $this->assertNull($outcome->voidedPayment());
        $this->assertNull($outcome->reissuedPayment());
    }

    public function test_branch_c_every_payment_voided_or_failed_writes_nothing_and_does_not_throw(): void
    {
        $orderId = 'order-1';

        $failed = Payment::create($orderId, 'card_stripe_unbound_but_never_resolved', $this->money(500), PaymentStatus::PENDING);
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', $this->now());
        app(PaymentRepository::class)->save($failed);

        $voided = $this->savedPendingAnsweredPayment($orderId, 500, attemptedAt: '2026-09-29 08:00:00');
        $voided->void($this->now());
        app(PaymentRepository::class)->save($voided);

        $outcome = DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(100), $this->now(), null));

        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertNull(DB::table('payments')->where('id', $voided->id())->value('confirmed_at'));
        // No third row was written — only the two fixture rows exist.
        $this->assertCount(2, $this->paymentsForOrder($orderId));

        $this->assertFalse($outcome->isRefunded());
        $this->assertFalse($outcome->isVoided());
    }

    // --- the anomaly guard --------------------------------------------------------

    /**
     * "Two simultaneously settled payments for one order" is NOT tested
     * here — confirmed unreachable through any real write path (Eloquent
     * or raw SQL alike): payments.pay_settled_order_unique
     * (payment-domain-design.md §5.1/§4.4) is a real MySQL unique index
     * on a stored generated column, so the database itself refuses a
     * second settled row before OrderRefunder's own guard could ever see
     * one. That guard is real, deliberate defense-in-depth — the same
     * "courtesy check backed by a DB guarantee" shape
     * OrderPaymentConfirmer's own settled-payment check already takes —
     * but it is provably untestable via a real round trip, which is
     * exactly why this test does not exist. See this stage's own final
     * report.
     */
    public function test_two_simultaneously_pending_eligible_payments_are_refused(): void
    {
        $orderId = 'order-1';
        $this->savedPendingAnsweredPayment($orderId, 500, attemptedAt: '2026-09-29 08:00:00');
        $this->savedPendingAnsweredPayment($orderId, 500, attemptedAt: '2026-09-29 09:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than one pending');

        DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(100), $this->now(), null));
    }

    public function test_one_settled_and_one_pending_simultaneously_is_refused(): void
    {
        $orderId = 'order-1';
        $this->savedSettledPayment($orderId, 500);
        $this->savedPendingAnsweredPayment($orderId, 500, attemptedAt: '2026-09-29 09:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settled payment and a separate pending payment');

        DB::transaction(fn () => $this->refunder()->refund($orderId, $this->money(100), $this->now(), null));
    }
}
