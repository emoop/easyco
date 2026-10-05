<?php

namespace Tests\Feature;

use App\Services\Exceptions\MoneyOnlyRefundRefusedException;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\RefundCapExceededException;
use App\Services\Exceptions\RefundPermissionDeniedException;
use App\Services\MoneyOnlyRefundRequest;
use App\Services\MoneyOnlyRefunder;
use App\Services\RefundStatusChanger;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\Payment;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 part 1 (shipping-domain-design.md §7.2.11): the money-only refund
 * ("refund without return") — goods 0, a shipping refund and/or an adjustment, a
 * mandatory reason, a payout channel; no SaleLine, no stock; settled payments only.
 */
class RefundMoneyOnlyTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function service(): MoneyOnlyRefunder
    {
        return app(MoneyOnlyRefunder::class);
    }

    private function request(int $shipping = 0, int $adjustment = 0, string $reason = 'goodwill', RefundChannel $channel = RefundChannel::CASH, ?string $key = null, ?int $deduction = null): MoneyOnlyRefundRequest
    {
        return new MoneyOnlyRefundRequest(
            shipping: $this->eur($shipping),
            adjustment: $this->eur($adjustment),
            reason: $reason,
            channel: $channel,
            operationKey: $key,
            deduction: $deduction === null ? null : $this->eur($deduction),
        );
    }

    /** 5 x 10.00 = 50.00 goods + 5.00 shipping = a settled payment of 55.00. */
    private function settledOrder(): array
    {
        return $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500);
    }

    private function actingAsCustomRole(array $permissions): void
    {
        $role = \EasyCo\Staff\Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        $this->actingAs(StaffModel::find($staff->id()), 'staff');
    }

    private function assertNothingWritten(array $order): void
    {
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_it_records_an_owed_refund_with_goods_zero_and_no_sale_line_or_stock_change(): void
    {
        $order = $this->settledOrder();
        $saleLinesBefore = DB::table('operational_sales_sale_lines')->count();
        $recorded = [];
        Hook::action('order.refund_recorded', function ($o, $refund) use (&$recorded): void {
            $recorded[] = $refund->id();
        });

        $result = $this->service()->record($order['orderId'], $this->request(shipping: 300, adjustment: 200, reason: 'goodwill for the delay'), $this->at());
        $refund = $result->refund();

        $this->assertFalse($result->wasReplay());
        $this->assertSame(PaymentRefundStatus::OWED, $refund->status());
        $this->assertSame(RefundChannel::CASH, $refund->channel());
        $this->assertSame('goodwill for the delay', $refund->reason());
        $this->assertSame(0, $refund->breakdown()->goods->minorValue());
        $this->assertSame(300, $refund->breakdown()->shipping->minorValue());
        $this->assertSame(200, $refund->breakdown()->adjustment->minorValue());
        $this->assertSame(0, $refund->breakdown()->deduction->minorValue());
        $this->assertSame([], $refund->breakdown()->lines, 'no goods line');
        $this->assertSame(500, $refund->amount()->minorValue());

        $this->assertSame($saleLinesBefore, DB::table('operational_sales_sale_lines')->count(), 'no SaleLine of any kind');
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'no stock moved');
        $this->assertSame(0, DB::table('payment_refund_lines')->count());

        $events = DB::table('order_events')->where('order_id', $order['orderId'])->get();
        $this->assertCount(1, $events);
        $this->assertSame('refund_owed', $events[0]->type);
        $this->assertSame((string) $refund->id(), (string) $events[0]->payment_refund_id, 'the event carries the refund id');
        $this->assertNull($events[0]->transaction_id);
        $this->assertSame('goodwill for the delay', $events[0]->reason);
        $this->assertSame([$refund->id()], $recorded, 'order.refund_recorded fired once, after the commit');
    }

    public function test_it_is_refused_by_name_on_a_pending_payment_and_voids_nothing(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);
        $pending = Payment::create($order['orderId'], 'cash_on_delivery', $this->eur(2000), PaymentStatus::PENDING);
        $pending->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($pending);

        try {
            $this->service()->record($order['orderId'], $this->request(adjustment: 100), $this->at());
            $this->fail('nothing has been paid, so nothing can be refunded.');
        } catch (MoneyOnlyRefundRefusedException $exception) {
            $this->assertSame(MoneyOnlyRefundRefusedException::PAYMENT_NOT_SETTLED, $exception->reason);
            $this->assertStringContainsString('no settled payment', $exception->getMessage());
        }

        $this->assertNothingWritten($order);
        $this->assertFalse(app(PaymentRepository::class)->findById((string) $pending->id())->isVoided(), 'the pending payment is untouched');
        $this->assertCount(1, app(PaymentRepository::class)->findByOrderId($order['orderId']), 'and nothing was reissued');
    }

    public function test_it_is_refused_by_name_when_the_order_has_no_payment_at_all(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);

        $this->expectException(MoneyOnlyRefundRefusedException::class);
        $this->expectExceptionMessage('no settled payment');

        $this->service()->record($order['orderId'], $this->request(adjustment: 100), $this->at());
    }

    public function test_it_is_refused_with_a_deduction(): void
    {
        $order = $this->settledOrder();

        try {
            $this->service()->record($order['orderId'], $this->request(adjustment: 300, deduction: 50), $this->at());
            $this->fail('a deduction needs goods to deduct from.');
        } catch (MoneyOnlyRefundRefusedException $exception) {
            $this->assertSame(MoneyOnlyRefundRefusedException::DEDUCTION_NOT_ALLOWED, $exception->reason);
        }

        $this->assertNothingWritten($order);

        // A zero deduction is no deduction.
        $this->assertSame(300, $this->service()->record($order['orderId'], $this->request(adjustment: 300, deduction: 0), $this->at())->refund()->amount()->minorValue());
    }

    public function test_it_requires_a_reason(): void
    {
        $order = $this->settledOrder();

        foreach (['', '   '] as $blank) {
            try {
                $this->service()->record($order['orderId'], $this->request(adjustment: 100, reason: $blank), $this->at());
                $this->fail('a money-only refund needs a reason.');
            } catch (MoneyOnlyRefundRefusedException $exception) {
                $this->assertSame(MoneyOnlyRefundRefusedException::REASON_REQUIRED, $exception->reason);
            }
        }

        $this->assertNothingWritten($order);
    }

    public function test_it_is_refused_when_shipping_and_adjustment_add_up_to_nothing(): void
    {
        $order = $this->settledOrder();

        try {
            $this->service()->record($order['orderId'], $this->request(), $this->at());
            $this->fail('a refund of 0 is no refund.');
        } catch (MoneyOnlyRefundRefusedException $exception) {
            $this->assertSame(MoneyOnlyRefundRefusedException::NOTHING_TO_REFUND, $exception->reason);
        }

        $this->assertNothingWritten($order);
    }

    public function test_it_respects_the_shipping_cap_across_all_refunds(): void
    {
        $order = $this->settledOrder(); // shipping 5.00

        $this->service()->record($order['orderId'], $this->request(shipping: 300), $this->at());

        try {
            $this->service()->record($order['orderId'], $this->request(shipping: 300), $this->at());
            $this->fail('shipping refunded so far 3.00 + 3.00 exceeds the order\'s 5.00.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::SHIPPING, $exception->cap());
            $this->assertSame(200, $exception->room()->minorValue());
        }

        $this->assertCount(1, DB::table('payment_refunds')->get(), 'the refused one wrote nothing');
        $this->assertSame(['refund_owed'], $this->eventTypesOf($order['orderId']));

        // The room that is left is usable.
        $this->service()->record($order['orderId'], $this->request(shipping: 200), $this->at());
        $this->assertCount(2, DB::table('payment_refunds')->get());
    }

    public function test_it_respects_the_total_cap_against_what_was_paid(): void
    {
        $order = $this->settledOrder(); // paid 55.00

        $this->service()->record($order['orderId'], $this->request(adjustment: 5000), $this->at());

        try {
            $this->service()->record($order['orderId'], $this->request(shipping: 400, adjustment: 200), $this->at());
            $this->fail('6.00 against the 5.00 still refundable.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::TOTAL, $exception->cap());
            $this->assertSame(500, $exception->room()->minorValue());
        }

        $this->assertCount(1, DB::table('payment_refunds')->get());
    }

    public function test_the_same_key_creates_one_refund_and_a_different_payload_under_it_is_refused(): void
    {
        $order = $this->settledOrder();
        $fired = 0;
        Hook::action('order.refund_recorded', function () use (&$fired): void {
            $fired++;
        });

        $first = $this->service()->record($order['orderId'], $this->request(shipping: 100, key: 'mo-key'), $this->at());
        $second = $this->service()->record($order['orderId'], $this->request(shipping: 100, key: 'mo-key'), $this->at());

        $this->assertFalse($first->wasReplay());
        $this->assertTrue($second->wasReplay());
        $this->assertSame($first->refund()->id(), $second->refund()->id(), 'the FIRST result');
        $this->assertCount(1, DB::table('payment_refunds')->get());
        $this->assertSame(['refund_owed'], $this->eventTypesOf($order['orderId']), 'one history entry');
        $this->assertSame(1, $fired, 'a replay fires no hook');

        $event = DB::table('order_events')->where('order_id', $order['orderId'])->first();
        $this->assertSame('mo-key', $event->operation_key);
        $this->assertSame(64, strlen((string) $event->operation_payload_hash));

        foreach ([
            'another shipping' => $this->request(shipping: 200, key: 'mo-key'),
            'an adjustment' => $this->request(shipping: 100, adjustment: 1, key: 'mo-key'),
            'another reason' => $this->request(shipping: 100, reason: 'something else', key: 'mo-key'),
            'another channel' => $this->request(shipping: 100, channel: RefundChannel::BANK, key: 'mo-key'),
        ] as $what => $different) {
            try {
                $this->service()->record($order['orderId'], $different, $this->at());
                $this->fail("{$what} under a reused key must be refused.");
            } catch (OperationKeyReusedException) {
                $this->assertTrue(true);
            }
        }

        $this->assertCount(1, DB::table('payment_refunds')->get(), 'none of them did anything');
    }

    public function test_it_is_refused_without_the_permission_of_the_payout_channel(): void
    {
        $order = $this->settledOrder();

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_BANK]);

        try {
            $this->service()->record($order['orderId'], $this->request(adjustment: 100, channel: RefundChannel::CASH), $this->at());
            $this->fail('a cash payout needs REFUND_CASH.');
        } catch (RefundPermissionDeniedException $exception) {
            $this->assertSame(RefundChannel::CASH, $exception->channel);
        }

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH]);

        try {
            $this->service()->record($order['orderId'], $this->request(adjustment: 100, channel: RefundChannel::BANK), $this->at());
            $this->fail('a bank payout needs REFUND_BANK, whatever the payment method.');
        } catch (RefundPermissionDeniedException $exception) {
            $this->assertSame(RefundChannel::BANK, $exception->channel);
        }

        Auth::guard('staff')->logout();

        try {
            $this->service()->record($order['orderId'], $this->request(adjustment: 100), $this->at());
            $this->fail('no acting staff member is a refusal (fail closed).');
        } catch (RefundPermissionDeniedException) {
            $this->assertTrue(true);
        }

        $this->assertNothingWritten($order);

        // And the permission the channel needs is enough.
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH]);
        $this->assertSame(100, $this->service()->record($order['orderId'], $this->request(adjustment: 100), $this->at())->refund()->amount()->minorValue());
    }

    public function test_the_owed_money_only_refund_can_be_cancelled_and_frees_its_room_without_a_storno(): void
    {
        $order = $this->settledOrder();
        $refund = $this->service()->record($order['orderId'], $this->request(shipping: 500), $this->at())->refund();
        $saleLines = DB::table('operational_sales_sale_lines')->count();

        app(RefundStatusChanger::class)->cancelOwed((string) $refund->id(), 'entered by mistake', $this->at());

        $this->assertSame($saleLines, DB::table('operational_sales_sale_lines')->count(), 'there was no goods line to reverse');
        $this->assertSame('cancelled', DB::table('payment_refunds')->where('id', $refund->id())->value('status'));
        $this->assertSame(500, $this->service()->record($order['orderId'], $this->request(shipping: 500), $this->at())->refund()->amount()->minorValue(), 'the cancelled refund freed its shipping room');
    }

    public function test_the_refusals_are_translated_into_bulgarian(): void
    {
        app()->setLocale('bg');

        foreach (['reason_required', 'payment_not_settled', 'deduction_not_allowed', 'nothing_to_refund'] as $reason) {
            $message = (new MoneyOnlyRefundRefusedException($reason))->getMessage();
            $this->assertStringNotContainsString('orders.money_only_refund', $message, $reason);
            $this->assertStringContainsString('Нищо не е записано', $message, $reason);
        }
    }
}
