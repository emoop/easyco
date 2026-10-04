<?php

namespace Tests\Feature;

use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\RefundCapExceededException;
use App\Services\Exceptions\RefundPermissionDeniedException;
use App\Services\Exceptions\RefundTransitionRefusedException;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use App\Services\RefundStatusChanger;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R2a (shipping-domain-design.md §7.2.5, §7.2.16): an OWED refund is paid out or
 * cancelled — by the service, with the UI out of the picture. Real MySQL; the order is
 * built, settled and returned through the real services, so the OWED refund under test is
 * the one the code really writes.
 */
class RefundStatusChangerTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function transitions(): RefundStatusChanger
    {
        return app(RefundStatusChanger::class);
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function t(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    /**
     * A settled order and ONE OWED refund against it: $quantity units of line 0 returned (goods = their computed share, or $entered).
     *
     * @return array{order: array, refund: \EasyCo\Payment\PaymentRefund}
     */
    private function owedRefund(string $method = 'bank_transfer', int $lineQuantity = 4, int $returnQuantity = 2, ?int $entered = null): array
    {
        $order = $this->refundableOrder([['quantity' => $lineQuantity, 'unit' => 1000]], method: $method);
        $line = $order['saleLineIds'][0];

        $this->changer()->recordReturn(
            $order['orderId'],
            [['originatingSaleLineId' => $line, 'quantityReturned' => $returnQuantity, 'restock' => true]],
            $this->at(),
            null,
            $entered !== null ? new RefundRequest(enteredGoodsByLine: [$line => $this->eur($entered)]) : null,
        );

        $refund = $this->refundsOf($order['payment'])[0];
        $this->assertSame(PaymentRefundStatus::OWED, $refund->status());

        return ['order' => $order, 'refund' => $refund];
    }

    private function reload(string $refundId): \EasyCo\Payment\PaymentRefund
    {
        return app(PaymentRefundRepository::class)->findById($refundId);
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffModel
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function events(string $orderId, string $type): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->where('type', $type)->orderBy('id')->get()->all();
    }

    private function refundLineRows(): array
    {
        return DB::table('operational_sales_sale_lines')->where('type', 'refund')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    // --- OWED -> PAID_OUT ---------------------------------------------------------------------------------------------------

    public function test_paying_out_sets_every_paid_out_fact_confirms_the_owed_total_and_writes_one_event(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $staff = Auth::guard('staff')->user();

        $result = $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 10:30:00'), 'REF-77', 'paid by transfer', $this->at());

        $this->assertFalse($result->wasReplay());
        $paid = $this->reload($refund->id());
        $this->assertSame(PaymentRefundStatus::PAID_OUT, $paid->status());
        $this->assertSame('2026-09-27 10:30:00', $paid->paidOutAt()->format('Y-m-d H:i:s'));
        $this->assertSame('REF-77', $paid->paidOutReference());
        $this->assertSame('paid by transfer', $paid->paidOutNote());
        $this->assertSame((string) $staff->id, $paid->paidOutBy());
        $this->assertSame($refund->amount()->minorValue(), $paid->amount()->minorValue(), 'PAID_OUT confirms exactly the owed total');

        $events = $this->events($order['orderId'], 'refund_paid_out');
        $this->assertCount(1, $events);
        $this->assertSame((int) $refund->id(), (int) $events[0]->payment_refund_id);
        $this->assertSame((string) $staff->id, (string) $events[0]->staff_id);
        $this->assertSame(2, count($this->events($order['orderId'], 'returned')) + count($this->events($order['orderId'], 'refund_owed')), 'the recording events are untouched');
    }

    public function test_a_payout_date_in_the_future_is_refused_and_nothing_is_written(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund('cash_on_delivery');

        try {
            $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-28 12:00:01'), null, null, $this->at());
            $this->fail('a future payout date must be refused');
        } catch (RefundTransitionRefusedException $e) {
            $this->assertSame('payout_in_future', $e->reason);
            $this->assertStringContainsString('cannot be in the future', $e->getMessage());
        }

        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($refund->id())->status());
        $this->assertSame([], $this->events($order['orderId'], 'refund_paid_out'));
    }

    public function test_a_bank_payout_requires_a_reference_and_a_cash_payout_does_not(): void
    {
        ['refund' => $bank] = $this->owedRefund('bank_transfer');
        ['refund' => $cash] = $this->owedRefund('cash_on_delivery');

        try {
            $this->transitions()->markPaidOut($bank->id(), $this->t('2026-09-27 10:00:00'), '  ', null, $this->at());
            $this->fail('a bank payout needs a reference');
        } catch (RefundTransitionRefusedException $e) {
            $this->assertSame('bank_reference_required', $e->reason);
        }
        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($bank->id())->status());

        $this->transitions()->markPaidOut($cash->id(), $this->t('2026-09-27 10:00:00'), null, null, $this->at());
        $this->assertSame(PaymentRefundStatus::PAID_OUT, $this->reload($cash->id())->status());
        $this->assertNull($this->reload($cash->id())->paidOutReference());
    }

    public function test_paying_out_is_idempotent_by_operation_key_one_event_one_hook_and_a_changed_payload_is_refused(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $fired = 0;
        Hook::action('order.refund_paid_out', function () use (&$fired): void {
            $fired++;
        });

        $first = $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 10:00:00'), 'REF-1', null, $this->at(), 'payout-key-1');
        $again = $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 10:00:00'), 'REF-1', null, $this->at(), 'payout-key-1');

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($again->wasReplay(), 'the repeat returns the first result');
        $this->assertSame($refund->id(), $again->refund()->id());
        $this->assertCount(1, $this->events($order['orderId'], 'refund_paid_out'), 'no second history entry');
        $this->assertSame(1, $fired, 'a replay fires no hook');

        $this->expectException(OperationKeyReusedException::class);
        $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 10:00:00'), 'REF-OTHER', null, $this->at(), 'payout-key-1');
    }

    public function test_only_an_owed_refund_can_be_paid_out(): void
    {
        ['refund' => $paid] = $this->owedRefund('cash_on_delivery');
        $this->transitions()->markPaidOut($paid->id(), $this->t('2026-09-27 10:00:00'), null, null, $this->at());
        ['refund' => $cancelled] = $this->owedRefund('cash_on_delivery');
        $this->transitions()->cancelOwed($cancelled->id(), 'withdrawn', $this->at());

        foreach ([$paid, $cancelled] as $refund) {
            try {
                $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 11:00:00'), null, null, $this->at());
                $this->fail('a refund that is not OWED must be refused');
            } catch (RefundTransitionRefusedException $e) {
                $this->assertSame('not_owed', $e->reason);
            }
        }

        $this->expectException(RefundTransitionRefusedException::class);
        $this->transitions()->markPaidOut('999999', $this->t('2026-09-27 11:00:00'), null, null, $this->at());
    }

    public function test_paying_out_is_refused_without_the_channels_permission_even_with_the_ui_bypassed(): void
    {
        ['order' => $order, 'refund' => $bank] = $this->owedRefund('bank_transfer');
        ['refund' => $cash] = $this->owedRefund('cash_on_delivery');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH]);
        try {
            $this->transitions()->markPaidOut($bank->id(), $this->t('2026-09-27 10:00:00'), 'REF', null, $this->at());
            $this->fail('REFUND_BANK is needed for a bank refund');
        } catch (RefundPermissionDeniedException $e) {
            $this->assertSame(RefundChannel::BANK, $e->channel);
        }
        $this->transitions()->markPaidOut($cash->id(), $this->t('2026-09-27 10:00:00'), null, null, $this->at());
        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($bank->id())->status());
        $this->assertSame([], $this->events($order['orderId'], 'refund_paid_out'));

        Auth::guard('staff')->logout();
        $this->expectException(RefundPermissionDeniedException::class);
        $this->transitions()->markPaidOut($bank->id(), $this->t('2026-09-27 10:00:00'), 'REF', null, $this->at());
    }

    // --- OWED -> CANCELLED ----------------------------------------------------------------------------------------------------

    public function test_cancelling_an_owed_refund_frees_its_room_in_every_cap_so_the_same_amount_can_be_recorded_again(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        [$line, ] = $order['saleLineIds'];
        $enter = fn (int $minor) => new RefundRequest(enteredGoodsByLine: [$line => $this->eur($minor)]);
        $return = fn (RefundRequest $request) => $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $line, 'quantityReturned' => 1, 'restock' => true]], $this->at(), null, $request);

        $return($enter(2000));                       // the whole payment, entered against one unit: OWED
        $first = $this->refundsOf($order['payment'])[0];

        try {
            $return($enter(2000));                   // the same amount again: no room left
            $this->fail('the line and the payment are exhausted');
        } catch (RefundCapExceededException $e) {
            $this->assertSame(RefundCapExceededException::LINE, $e->cap());
            $this->assertSame(0, $e->room()->minorValue());
        }

        $this->transitions()->cancelOwed($first->id(), 'recorded by mistake', $this->at());

        $return($enter(2000));                       // the very same amount is accepted again
        $refunds = $this->refundsOf($order['payment']);
        $this->assertCount(2, $refunds);
        $this->assertSame([PaymentRefundStatus::CANCELLED, PaymentRefundStatus::OWED], array_map(static fn ($r) => $r->status(), $refunds));
    }

    public function test_cancelling_records_who_why_and_when_and_writes_one_event_pointing_at_the_storno_transaction(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $staff = Auth::guard('staff')->user();

        $result = $this->transitions()->cancelOwed($refund->id(), 'customer withdrew the claim', $this->t('2026-09-28 13:00:00'));

        $this->assertFalse($result->wasReplay());
        $cancelled = $this->reload($refund->id());
        $this->assertSame(PaymentRefundStatus::CANCELLED, $cancelled->status());
        $this->assertSame('customer withdrew the claim', $cancelled->cancelledReason());
        $this->assertSame((string) $staff->id, $cancelled->cancelledBy());
        $this->assertSame('2026-09-28 13:00:00', $cancelled->cancelledAt()->format('Y-m-d H:i:s'));

        $events = $this->events($order['orderId'], 'refund_cancelled');
        $this->assertCount(1, $events);
        $this->assertSame((int) $refund->id(), (int) $events[0]->payment_refund_id);
        $this->assertSame('customer withdrew the claim', $events[0]->reason);
        $this->assertNotNull($events[0]->transaction_id, 'the event points at the storno lines');
    }

    public function test_a_paid_out_refund_and_a_legacy_refund_can_never_be_cancelled(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund('cash_on_delivery');
        $this->transitions()->markPaidOut($refund->id(), $this->t('2026-09-27 10:00:00'), null, null, $this->at());

        // A legacy refund: written before the owed model, mapped to PAID_OUT by its migration.
        DB::table('payment_refunds')->insert([
            'payment_id' => $order['payment']->id(), 'order_id' => $order['orderId'], 'amount_minor' => 100, 'amount_currency' => 'EUR', 'channel' => 'cash',
            'goods_minor' => 100, 'status' => 'paid_out', 'paid_out_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyId = (string) DB::table('payment_refunds')->max('id');
        $linesBefore = DB::table('operational_sales_sale_lines')->count();

        foreach ([$refund->id(), $legacyId] as $id) {
            try {
                $this->transitions()->cancelOwed($id, 'trying', $this->at());
                $this->fail('a paid-out refund must never be cancelled');
            } catch (RefundTransitionRefusedException $e) {
                $this->assertSame('not_owed', $e->reason);
            }
        }

        $this->assertSame($linesBefore, DB::table('operational_sales_sale_lines')->count(), 'no storno was written');
        $this->assertSame([], $this->events($order['orderId'], 'refund_cancelled'));
    }

    public function test_cancelling_needs_a_reason(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();

        foreach (['', '   '] as $reason) {
            try {
                $this->transitions()->cancelOwed($refund->id(), $reason, $this->at());
                $this->fail('a reason is mandatory');
            } catch (RefundTransitionRefusedException $e) {
                $this->assertSame('reason_required', $e->reason);
            }
        }

        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($refund->id())->status());
        $this->assertSame([], $this->events($order['orderId'], 'refund_cancelled'));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->count());
    }

    public function test_cancelling_leaves_the_goods_returned_and_restocked_the_stock_and_the_refund_lines_untouched(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $stock = $this->stockOf($order['variationIds'][0]);
        $refundLines = $this->refundLineRows();
        $returned = app(SaleLineRepository::class)->sumQuantityReturnedForOriginatingLine($order['saleLineIds'][0]);

        $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at());

        $this->assertSame($stock, $this->stockOf($order['variationIds'][0]), 'no stock change: the goods stay returned and restocked');
        $this->assertSame(12, $stock);
        $this->assertSame($refundLines, $this->refundLineRows(), 'not one REFUND line was touched');
        $this->assertSame($returned, app(SaleLineRepository::class)->sumQuantityReturnedForOriginatingLine($order['saleLineIds'][0]));
        $this->assertSame('shipped', DB::table('orders')->where('id', $order['orderId'])->value('status'), 'the order does not move');
    }

    public function test_cancelling_appends_one_storno_line_per_refund_line_equal_to_its_entered_amount_and_linked_to_it(): void
    {
        $order = $this->refundableOrder([['quantity' => 3, 'unit' => 1000], ['quantity' => 2, 'unit' => 500]]);
        [$a, $b] = $order['saleLineIds'];
        $this->changer()->recordReturn($order['orderId'], [
            ['originatingSaleLineId' => $a, 'quantityReturned' => 2, 'restock' => true],
            ['originatingSaleLineId' => $b, 'quantityReturned' => 1, 'restock' => true],
        ], $this->at(), null, new RefundRequest(enteredGoodsByLine: [$a => $this->eur(1700)]));
        $refund = $this->refundsOf($order['payment'])[0];
        $refundLines = collect($this->refundLineRows())->keyBy('originating_sale_line_id');

        $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at());

        $stornos = DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->orderBy('id')->get();
        $this->assertCount(2, $stornos, 'one per refund line');
        $byOrigin = $stornos->keyBy('originating_sale_line_id');

        foreach ([$a => 1700, $b => 500] as $saleLineId => $amount) {
            $refundLine = $refundLines[$saleLineId];
            $storno = $byOrigin[$refundLine['id']];
            $this->assertSame($amount, (int) $storno->amount_minor, 'equal to the line\'s ENTERED amount');
            $this->assertNull($storno->quantity_returned, 'no returned quantity: the goods stay returned');
            $this->assertSame($refundLine['priceable_id'], $storno->priceable_id);
            $this->assertSame('withdrawn', $storno->return_reason);
        }

        $this->assertCount(1, $stornos->pluck('transaction_id')->unique(), 'all in one storno Transaction');
        $this->assertSame((string) $stornos[0]->transaction_id, (string) $this->events($order['orderId'], 'refund_cancelled')[0]->transaction_id);
    }

    public function test_cancelling_is_idempotent_by_operation_key_one_storno_set_one_event_one_hook(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $fired = 0;
        Hook::action('order.refund_cancelled', function () use (&$fired): void {
            $fired++;
        });

        $first = $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at(), 'cancel-key-1');
        $again = $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at(), 'cancel-key-1');

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($again->wasReplay());
        $this->assertCount(1, $this->events($order['orderId'], 'refund_cancelled'));
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->count());
        $this->assertSame(1, $fired);

        $this->expectException(OperationKeyReusedException::class);
        $this->transitions()->cancelOwed($refund->id(), 'another reason', $this->at(), 'cancel-key-1');
    }

    public function test_cancelling_is_refused_without_the_channels_permission_even_with_the_ui_bypassed(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund('bank_transfer');
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH]);

        try {
            $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at());
            $this->fail('REFUND_BANK is needed');
        } catch (RefundPermissionDeniedException $e) {
            $this->assertSame(Permission::REFUND_BANK, $e->permission);
        }

        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($refund->id())->status());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->count());
        $this->assertSame([], $this->events($order['orderId'], 'refund_cancelled'));
    }

    public function test_a_refund_whose_lines_cannot_be_matched_to_its_return_is_refused_not_guessed(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        // An OWED refund recorded before R1b: its event carries no refund reference.
        DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'refund_owed')->update(['payment_refund_id' => null]);

        try {
            $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at());
            $this->fail('nothing may be guessed');
        } catch (RefundTransitionRefusedException $e) {
            $this->assertSame('refund_lines_unlinked', $e->reason);
        }

        $this->assertSame(PaymentRefundStatus::OWED, $this->reload($refund->id())->status());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->count());
    }

    // --- the ledger -------------------------------------------------------------------------------------------------------------

    public function test_a_storno_is_invisible_to_every_quantity_sum(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $line = $order['saleLineIds'][0];
        $repository = app(SaleLineRepository::class);
        $before = [$repository->sumQuantityReturnedForOriginatingLine($line), $repository->sumQuantityReturnedForOriginatingLines([$line]), $repository->sumQuantityEditedAwayForOriginatingLine($line)];

        $this->transitions()->cancelOwed($refund->id(), 'withdrawn', $this->at());

        $this->assertSame([2, [$line => 2], 0], $before);
        $this->assertSame($before, [$repository->sumQuantityReturnedForOriginatingLine($line), $repository->sumQuantityReturnedForOriginatingLines([$line]), $repository->sumQuantityEditedAwayForOriginatingLine($line)], 'a storno changes nothing about what counts as returned');

        // And a further return still sees exactly the units that are left: 4 - 2 = 2.
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $line, 'quantityReturned' => 2, 'restock' => true]], $this->at());
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'), 'all four units are accounted for');
    }

    public function test_the_ledger_agrees_with_the_refund_rows_for_every_product_line(): void
    {
        $order = $this->refundableOrder([['quantity' => 6, 'unit' => 1000], ['quantity' => 3, 'unit' => 400]]);
        [$a, $b] = $order['saleLineIds'];
        $ret = fn (array $lines) => $this->changer()->recordReturn($order['orderId'], $lines, $this->at());

        $ret([['originatingSaleLineId' => $a, 'quantityReturned' => 2, 'restock' => true]]);
        $ret([['originatingSaleLineId' => $a, 'quantityReturned' => 1, 'restock' => true], ['originatingSaleLineId' => $b, 'quantityReturned' => 1, 'restock' => true]]);
        $ret([['originatingSaleLineId' => $a, 'quantityReturned' => 2, 'restock' => true]]);
        [$r1, $r2, $r3] = $this->refundsOf($order['payment']);
        $this->transitions()->cancelOwed($r2->id(), 'withdrawn', $this->at());
        $this->transitions()->markPaidOut($r3->id(), $this->t('2026-09-27 10:00:00'), 'REF', null, $this->at());

        foreach ([$a, $b] as $saleLineId) {
            // What the refund rows say is still owed or paid: lines of every refund that is not cancelled.
            $live = (int) DB::table('payment_refund_lines as l')->join('payment_refunds as r', 'r.id', '=', 'l.payment_refund_id')
                ->where('l.sale_line_id', $saleLineId)->where('r.status', '!=', 'cancelled')->sum('l.amount_minor');

            // What the ledger says: REFUND amounts minus the storno amounts that point at those REFUND lines.
            $refunded = (int) DB::table('operational_sales_sale_lines')->where('type', 'refund')->where('originating_sale_line_id', $saleLineId)->sum('amount_minor');
            $stornoed = (int) DB::table('operational_sales_sale_lines as s')->join('operational_sales_sale_lines as f', 'f.id', '=', 's.originating_sale_line_id')
                ->where('s.type', 'refund_reversal')->where('f.originating_sale_line_id', $saleLineId)->sum('s.amount_minor');

            $this->assertSame($live, $refunded - $stornoed, "product line {$saleLineId}: non-cancelled refund lines = REFUND amounts - storno amounts");
        }

        $this->assertSame(1000 * 2 + 1000 * 2, (int) DB::table('payment_refund_lines')->where('sale_line_id', $a)->whereIn('payment_refund_id', [$r1->id(), $r3->id()])->sum('amount_minor'));
    }

    // --- hooks ---------------------------------------------------------------------------------------------------------------------

    public function test_each_hook_fires_once_and_only_after_the_commit(): void
    {
        $baseLevel = DB::transactionLevel();
        $seen = [];
        $observe = function (string $hook) use (&$seen, $baseLevel): void {
            Hook::action($hook, function ($order, $refund) use (&$seen, $hook, $baseLevel): void {
                $seen[$hook][] = [
                    'level' => DB::transactionLevel() === $baseLevel,
                    'status' => DB::table('payment_refunds')->where('id', $refund->id())->value('status'),
                ];
            });
        };
        foreach (['order.refund_recorded', 'order.refund_paid_out', 'order.refund_cancelled'] as $hook) {
            $observe($hook);
        }

        ['refund' => $toPay] = $this->owedRefund('cash_on_delivery');
        $this->assertSame(['order.refund_recorded' => [['level' => true, 'status' => 'owed']]], $seen, 'recording an OWED refund fires refund_recorded and NOT refund_paid_out');

        $this->transitions()->markPaidOut($toPay->id(), $this->t('2026-09-27 10:00:00'), null, null, $this->at());
        $this->assertSame([['level' => true, 'status' => 'paid_out']], $seen['order.refund_paid_out'], 'after the commit: the new state is already stored and no transaction is open');

        ['refund' => $toCancel] = $this->owedRefund('cash_on_delivery');
        $this->transitions()->cancelOwed($toCancel->id(), 'withdrawn', $this->at());
        $this->assertSame([['level' => true, 'status' => 'cancelled']], $seen['order.refund_cancelled']);

        $this->assertCount(2, $seen['order.refund_recorded']);
        $this->assertCount(1, $seen['order.refund_paid_out']);
        $this->assertCount(1, $seen['order.refund_cancelled']);
    }

    public function test_a_refused_transition_fires_no_hook(): void
    {
        ['refund' => $refund] = $this->owedRefund('cash_on_delivery');
        $fired = 0;
        foreach (['order.refund_paid_out', 'order.refund_cancelled'] as $hook) {
            Hook::action($hook, function () use (&$fired): void {
                $fired++;
            });
        }

        try {
            $this->transitions()->cancelOwed($refund->id(), '', $this->at());
        } catch (RefundTransitionRefusedException) {
        }
        try {
            $this->transitions()->markPaidOut($refund->id(), $this->t('2030-01-01 00:00:00'), null, null, $this->at());
        } catch (RefundTransitionRefusedException) {
        }

        $this->assertSame(0, $fired);
    }

    public function test_the_old_refunded_hook_is_gone(): void
    {
        $source = file_get_contents(app_path('Services/OrderStatusChanger.php'));

        $this->assertStringNotContainsString("Hook::fire('order.refunded'", $source);
    }
}
