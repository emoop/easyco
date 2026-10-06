<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\PaymentReceiptRefusedException;
use App\Services\Exceptions\PaymentReceiptUnreconciledException;
use App\Services\Exceptions\PaymentReconcilePermissionDeniedException;
use App\Services\OrderEditor;
use App\Services\OrderPromotionCodeChange;
use App\Services\OrderStatusChanger;
use App\Services\PaymentMismatchAcceptanceResult;
use App\Services\PaymentReceiptRecorder;
use App\Services\RefundCapGuard;
use App\Services\RefundRequest;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Refunds R4a-3 (shipping-domain-design.md §7.2.20 §4a, §5, §10): accepting a bank transfer that is short or
 * over as the payment's settled amount. The permission `payment_reconcile` is enforced by the SERVICE.
 * 100.00 EUR is expected; the order was placed 2026-09-28 09:00 UTC and the clock is 2026-09-28 12:00 UTC.
 */
class PaymentMismatchAcceptanceTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    /** @var list<string> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();

        foreach (['order.payment_receipt_recorded', 'order.payment_confirmed', 'order.payment_receipt_corrected', 'order.payment_mismatch_accepted'] as $name) {
            Hook::action($name, function (...$arguments) use ($name): void {
                $this->hooks[] = $name;

                if ($name === 'order.payment_mismatch_accepted') {
                    $this->acceptedHookReason = $arguments[2] ?? null;
                }
            });
        }
    }

    private ?string $acceptedHookReason = null;

    private function recorder(): PaymentReceiptRecorder
    {
        return app(PaymentReceiptRecorder::class);
    }

    private function receive(array $order, int $minor, string $reference = 'BG-REF-1'): void
    {
        $this->recorder()->record((string) $order['payment']->id(), $this->eur($minor), '2026-09-28', $reference, $this->at());
    }

    private function accept(array $order, int $minor, string $reason = 'agreed by phone', ?string $key = null): PaymentMismatchAcceptanceResult
    {
        return $this->recorder()->acceptMismatch((string) $order['payment']->id(), $this->eur($minor), $reason, $this->at(), $key);
    }

    private function actingAsRole(string $roleName): StaffModel
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create('acc-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function snapshot(array $order): array
    {
        return [
            DB::table('payment_receipts')->count(),
            DB::table('order_events')->where('order_id', $order['orderId'])->count(),
            (array) $this->paymentRow($order['payment']),
        ];
    }

    private function assertRefused(string $reason, array $order, callable $call, ?string $field = null): PaymentReceiptRefusedException
    {
        $before = $this->snapshot($order);
        $this->hooks = [];

        try {
            $call();
            $this->fail("expected {$reason}.");
        } catch (PaymentReceiptRefusedException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($field, $exception->field(), "field of {$reason}");
            $this->assertStringNotContainsString('orders.payment_receipt', $exception->getMessage(), 'a translated sentence, not a key');
        }

        $this->assertSame($before, $this->snapshot($order), 'nothing written');
        $this->assertSame([], $this->hooks, 'no hook on a refusal');

        return $exception;
    }

    // --- the acceptance --------------------------------------------------------------------------------------

    public function test_an_underpayment_of_90_of_100_settles_for_exactly_90_with_the_reason_and_keeps_the_price(): void
    {
        $staff = $this->actingAsAdministrator();
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->hooks = [];

        $result = $this->accept($order, 9000, '  customer paid 90, agreed by phone  ', 'k-accept');

        $this->assertFalse($result->wasReplay());
        $this->assertTrue($result->payment()->isSettled());
        $this->assertSame(9000, $result->payment()->settledAmount()->minorValue());

        $row = $this->paymentRow($order['payment']);
        $this->assertSame(10000, (int) $row->amount_minor, 'payments.amount is still the EXPECTED amount');
        $this->assertSame(9000, (int) $row->settled_amount_minor);
        $this->assertSame('customer paid 90, agreed by phone', $row->settlement_reason, 'trimmed');
        $this->assertSame('2026-09-28 12:00:00', $row->confirmed_at);
        $this->assertSame(10000, (int) DB::table('orders')->where('id', $order['orderId'])->value('total_minor'), 'the order total does not change');

        $events = DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->get()->all();
        $this->assertSame(['payment_receipt_recorded', 'payment_mismatch_accepted', 'payment_confirmed'], array_map(fn ($e) => $e->type, $events));
        $this->assertSame('customer paid 90, agreed by phone', $events[1]->reason);
        $this->assertSame((string) $order['payment']->id(), (string) $events[1]->payment_id);
        $this->assertNull($events[1]->payment_receipt_id);
        $this->assertSame('k-accept', $events[1]->operation_key);
        $this->assertNotNull($events[1]->operation_payload_hash);
        $this->assertSame((string) $staff->id, (string) $events[1]->staff_id);
        $this->assertNull($events[2]->operation_key);

        $this->assertSame(['order.payment_mismatch_accepted', 'order.payment_confirmed'], $this->hooks);
        $this->assertSame('customer paid 90, agreed by phone', $this->acceptedHookReason);
    }

    public function test_an_overpayment_of_110_of_100_settles_for_exactly_110(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 11000);

        $result = $this->accept($order, 11000, 'surplus to be refunded');

        $this->assertSame(11000, $result->payment()->settledAmount()->minorValue());
        $row = $this->paymentRow($order['payment']);
        $this->assertSame(10000, (int) $row->amount_minor);
        $this->assertSame(11000, (int) $row->settled_amount_minor);
    }

    public function test_after_acceptance_the_cap_readers_say_90_and_110(): void
    {
        $under = $this->bankOrder();
        $this->receive($under, 9000);
        $this->accept($under, 9000);
        $this->assertSame(9000, app(RefundCapGuard::class)->totalRoom($this->freshPayment($under['payment']))->minorValue());

        $over = $this->bankOrder();
        $this->receive($over, 11000);
        $this->accept($over, 11000);
        $this->assertSame(11000, app(RefundCapGuard::class)->totalRoom($this->freshPayment($over['payment']))->minorValue());
    }

    public function test_cancel_is_released_by_the_acceptance_and_the_refund_is_capped_at_the_accepted_amount(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        try {
            app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());
            $this->fail('refused while unreconciled.');
        } catch (PaymentReceiptUnreconciledException) {
            // expected
        }

        $this->accept($order, 9000);

        // Released: the refusal is no longer the unreconciled one — the ordinary cap now speaks (goods 100.00 > 90.00).
        try {
            app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());
            $this->fail('the goods share of 100.00 exceeds the 90.00 settled.');
        } catch (\App\Services\Exceptions\RefundCapExceededException $exception) {
            $this->assertSame(9000, $exception->room()->minorValue());
        }

        // The merchant enters a smaller goods amount, and the cancel goes through.
        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at(), null, [], new RefundRequest([$order['saleLineIds'][0] => $this->eur(9000)]));

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_cancel_of_an_accepted_overpayment_returns_the_goods_and_the_surplus_stays_for_a_money_only_refund(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 11000);
        $this->accept($order, 11000);

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
        $this->assertSame(1000, app(RefundCapGuard::class)->totalRoom($this->freshPayment($order['payment']))->minorValue(), '110.00 settled, 100.00 of goods refunded: the 10.00 surplus is still refundable');
    }

    public function test_a_return_is_released_by_the_acceptance(): void
    {
        $order = $this->bankOrder(OrderStatus::SHIPPED);
        $this->receive($order, 9000);
        $line = [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]];

        try {
            app(OrderStatusChanger::class)->recordReturn($order['orderId'], $line, $this->at());
            $this->fail('refused while unreconciled.');
        } catch (PaymentReceiptUnreconciledException) {
            // expected
        }

        $this->accept($order, 9000);

        app(OrderStatusChanger::class)->recordReturn($order['orderId'], $line, $this->at());

        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'returned')->count());
    }

    public function test_an_edit_is_no_longer_refused_as_unreconciled_after_the_acceptance_but_a_settled_payment_still_ends_editing(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->accept($order, 9000);

        try {
            app(OrderEditor::class)->apply(
                orderId: $order['orderId'],
                expectedRevision: (int) DB::table('orders')->where('id', $order['orderId'])->value('edit_revision'),
                lineChanges: [],
                delivery: null,
                promotionCode: OrderPromotionCodeChange::unchanged(),
                editedBy: null,
                editedByName: null,
                reason: null,
                occurredAt: $this->at(),
            );
            $this->fail('a settled payment ends editing (E3).');
        } catch (OrderNotEditableException $exception) {
            $this->assertTrue($exception->isBecauseOfSettledPayment());
        }
    }

    // --- the permission, enforced by the service ------------------------------------------------------------

    public function test_a_manager_without_payment_reconcile_is_refused_by_the_service_itself_and_an_administrator_is_not(): void
    {
        $order = $this->bankOrder();
        $this->actingAsRole('Manager');
        $this->receive($order, 9000);
        $before = $this->snapshot($order);
        $this->hooks = [];

        try {
            $this->accept($order, 9000);
            $this->fail('a Manager does not hold payment_reconcile.');
        } catch (PaymentReconcilePermissionDeniedException $exception) {
            $this->assertStringContainsString('permission', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame([], $this->hooks);
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());

        $this->actingAsRole('Administrator');
        $this->accept($order, 9000);

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
    }

    public function test_the_refusal_is_in_bulgarian_in_bg_and_without_any_acting_staff_member_it_fails_closed(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        Auth::guard('staff')->logout();
        App::setLocale('bg');

        try {
            $this->accept($order, 9000);
            $this->fail('an unattended caller must not make a money decision.');
        } catch (PaymentReconcilePermissionDeniedException $exception) {
            $this->assertStringContainsString('право', $exception->getMessage());
        }

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
    }

    // --- the refusals ----------------------------------------------------------------------------------------

    public function test_accepting_a_non_bank_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');
        $this->appendReceipt($order['payment'], 9000);

        $this->assertRefused(PaymentReceiptRefusedException::NOT_BANK_TRANSFER, $order, fn () => $this->accept($order, 9000));
    }

    public function test_accepting_a_settled_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 10000);

        $this->assertRefused(PaymentReceiptRefusedException::PAYMENT_SETTLED, $order, fn () => $this->accept($order, 10000));
    }

    public function test_accepting_an_already_accepted_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->accept($order, 9000);

        $this->assertRefused(PaymentReceiptRefusedException::PAYMENT_SETTLED, $order, fn () => $this->accept($order, 9000, 'again'));
    }

    public function test_accepting_a_voided_payment_is_refused_by_name(): void
    {
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 9000);
        $order['payment']->void(new DateTimeImmutable('2026-09-28 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $this->assertRefused(PaymentReceiptRefusedException::PAYMENT_VOIDED, $order, fn () => $this->accept($order, 9000));
    }

    public function test_accepting_a_payment_without_any_receipt_is_refused_by_name(): void
    {
        $order = $this->bankOrder();

        $this->assertRefused(PaymentReceiptRefusedException::NO_EFFECTIVE_RECEIPT, $order, fn () => $this->accept($order, 9000));
    }

    public function test_accepting_when_the_receipts_equal_the_expected_amount_is_refused_by_name(): void
    {
        // Cannot arise through record() (it settles on equality); built straight through the repository.
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 10000);

        $this->assertRefused(PaymentReceiptRefusedException::NOTHING_TO_ACCEPT, $order, fn () => $this->accept($order, 10000));
    }

    public function test_a_receipt_that_arrived_between_the_dialog_and_the_submit_makes_the_acceptance_fail_by_name(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'FIRST');
        // The merchant saw 90.00 and opened the dialog; then another 5.00 was recorded.
        $this->receive($order, 500, 'SECOND');

        $exception = $this->assertRefused(PaymentReceiptRefusedException::ACCEPTED_AMOUNT_CHANGED, $order, fn () => $this->accept($order, 9000), 'accepted_amount');
        $this->assertStringContainsString('95.00 EUR', $exception->getMessage(), 'the new figure is named');

        $this->accept($order, 9500);
        $this->assertSame(9500, $this->freshPayment($order['payment'])->settledAmount()->minorValue());
    }

    public function test_an_accepted_amount_in_another_currency_counts_as_changed(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->assertRefused(
            PaymentReceiptRefusedException::ACCEPTED_AMOUNT_CHANGED,
            $order,
            fn () => $this->recorder()->acceptMismatch((string) $order['payment']->id(), Money::fromMinorUnits(9000, 'USD'), 'reason', $this->at()),
            'accepted_amount',
        );
    }

    public function test_the_reason_is_mandatory_at_most_255_characters_and_one_line_of_visible_text(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        foreach (['', '   ', "\u{00A0}", "\u{200B}"] as $blank) {
            $this->assertRefused(PaymentReceiptRefusedException::REASON_BLANK, $order, fn () => $this->accept($order, 9000, $blank), 'reason');
        }

        $this->assertRefused(PaymentReceiptRefusedException::REASON_TOO_LONG, $order, fn () => $this->accept($order, 9000, str_repeat('x', 256)), 'reason');
        $this->assertRefused(PaymentReceiptRefusedException::REASON_TOO_LONG, $order, fn () => $this->accept($order, 9000, str_repeat('x', 100000)), 'reason');

        foreach (["a\nb", "a\0b", "a\tb", "a\u{200B}b", "a\u{202E}b", "a\xC3\x28b"] as $bad) {
            $this->assertRefused(PaymentReceiptRefusedException::REASON_INVALID, $order, fn () => $this->accept($order, 9000, $bad), 'reason');
        }

        $this->accept($order, 9000, str_repeat('Я', 255));
        $this->assertSame(255, mb_strlen((string) $this->paymentRow($order['payment'])->settlement_reason));
    }

    public function test_an_unknown_payment_is_an_invalid_argument_and_a_bad_key_too(): void
    {
        try {
            $this->recorder()->acceptMismatch('999999', $this->eur(100), 'r', $this->at());
            $this->fail('unknown payment');
        } catch (InvalidArgumentException) {
            // expected
        }

        $order = $this->bankOrder();
        $this->receive($order, 9000);

        foreach ([str_repeat('k', 65), '', '  '] as $key) {
            try {
                $this->accept($order, 9000, 'r', $key);
                $this->fail('bad key');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
    }

    // --- idempotency -----------------------------------------------------------------------------------------

    public function test_a_replay_with_the_same_key_writes_nothing_and_fires_no_hook(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $first = $this->accept($order, 9000, 'phone', 'k-acc');
        $before = $this->snapshot($order);
        $this->hooks = [];

        $again = $this->accept($order, 9000, 'phone', 'k-acc');

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($again->wasReplay());
        $this->assertTrue($again->payment()->isSettled());
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame([], $this->hooks);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'payment_mismatch_accepted')->count());
    }

    public function test_the_same_key_with_other_contents_is_refused(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->accept($order, 9000, 'phone', 'k-acc');
        $before = $this->snapshot($order);
        $this->hooks = [];

        $this->expectException(OperationKeyReusedException::class);

        try {
            $this->accept($order, 9000, 'a different reason', 'k-acc');
        } finally {
            $this->assertSame($before, $this->snapshot($order));
            $this->assertSame([], $this->hooks);
        }
    }

    public function test_the_one_click_confirmation_stays_refused_for_a_mismatching_receipt(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->expectException(PaymentReceiptUnreconciledException::class);

        app(\App\Services\OrderPaymentConfirmer::class)->confirm((string) $order['payment']->id(), $this->at());
    }
}
