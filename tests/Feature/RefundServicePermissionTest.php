<?php

namespace Tests\Feature;

use App\Services\Exceptions\RefundPermissionDeniedException;
use App\Services\OrderStatusChanger;
use App\Services\RefundPermissionPolicy;
use App\Services\RefundRequest;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1b (shipping-domain-design.md §7.2.8, owner decision R1a-5): the money
 * permission is enforced by the SERVICE, not only by what the panel shows. The
 * tests call the services directly — the UI is bypassed — as staff who lack the
 * channel's permission, and as nobody at all.
 */
class RefundServicePermissionTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function actingAsRole(string $roleName): StaffModel
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create('perm-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffModel
    {
        $role = \EasyCo\Staff\Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function returning(string $lineId): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => 2, 'restock' => true]];
    }

    private function assertNothingWritten(array $order): void
    {
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back too');
        $this->assertSame([], $this->refundsOf($order['payment']));
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    public function test_staff_without_refund_cash_cannot_record_a_cash_refund_even_with_the_ui_bypassed(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_BANK]);
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'cash_on_delivery');

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at());
            $this->fail('the service must refuse a cash refund without REFUND_CASH.');
        } catch (RefundPermissionDeniedException $exception) {
            $this->assertSame(RefundChannel::CASH, $exception->channel);
            $this->assertSame(Permission::REFUND_CASH, $exception->permission);
            $this->assertStringContainsString('refund in cash', $exception->getMessage());
        }

        $this->assertNothingWritten($order);
    }

    public function test_a_manager_cannot_record_a_bank_refund_but_can_a_cash_one(): void
    {
        // Manager holds REFUND_CASH and not REFUND_BANK (staff-access-domain-design.md §3.1).
        $this->actingAsRole('Manager');
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'bank_transfer');

        try {
            $this->changer()->cancel($order['orderId'], $this->at());
            $this->fail('the service must refuse a bank refund without REFUND_BANK.');
        } catch (RefundPermissionDeniedException $exception) {
            $this->assertSame(RefundChannel::BANK, $exception->channel);
            $this->assertSame(Permission::REFUND_BANK, $exception->permission);
        }

        $this->assertNothingWritten($order);

        $cash = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'cash_on_delivery');
        $this->changer()->recordReturn($cash['orderId'], $this->returning($cash['saleLineIds'][0]), $this->at());
        $this->assertCount(1, $this->refundsOf($cash['payment']), 'the Manager may refund in cash');
    }

    public function test_a_bank_only_role_is_refused_a_cash_refund(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_BANK]);
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'cash_on_delivery');

        $this->expectException(RefundPermissionDeniedException::class);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at());
    }

    public function test_the_channel_chosen_for_the_refund_decides_the_permission_not_the_payment_method(): void
    {
        // The documented loophole of the old rule: a cash-on-delivery order whose refund will be paid by BANK.
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH]);
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'cash_on_delivery');

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at(), null, new RefundRequest(channel: RefundChannel::BANK));
            $this->fail('REFUND_BANK is needed for a bank-channel refund, whatever the payment method.');
        } catch (RefundPermissionDeniedException $exception) {
            $this->assertSame(RefundChannel::BANK, $exception->channel);
        }
    }

    public function test_with_no_acting_staff_at_all_the_refund_is_refused_fail_closed(): void
    {
        Auth::guard('staff')->logout();
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);

        $this->expectException(RefundPermissionDeniedException::class);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0]), $this->at());
    }

    public function test_an_administrator_may_record_both_channels(): void
    {
        $this->actingAsRole('Administrator');
        $cash = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'cash_on_delivery');
        $bank = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], method: 'bank_transfer');

        $this->changer()->recordReturn($cash['orderId'], $this->returning($cash['saleLineIds'][0]), $this->at());
        $this->changer()->recordReturn($bank['orderId'], $this->returning($bank['saleLineIds'][0]), $this->at());

        $this->assertCount(1, $this->refundsOf($cash['payment']));
        $this->assertCount(1, $this->refundsOf($bank['payment']));
    }

    public function test_cancelling_an_order_with_a_pending_payment_needs_no_refund_permission(): void
    {
        // No money was paid, so none is refunded (§7.2.8): ORDER_MANAGE alone, which the panel checks.
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);
        $payment = \EasyCo\Payment\Payment::create($order['orderId'], 'cash_on_delivery', $this->eur(2000), \EasyCo\Payment\Enums\PaymentStatus::PENDING);
        $payment->recordAttemptResult(\EasyCo\Payment\Enums\PaymentStatus::PENDING, null, null, new \DateTimeImmutable('2026-09-28 09:00:00'));
        app(\EasyCo\Payment\Contracts\PaymentRepository::class)->save($payment);

        $this->changer()->cancel($order['orderId'], $this->at());

        $this->assertSame(12, $this->stockOf($order['variationIds'][0]));
    }

    public function test_the_ui_clause_and_the_service_read_the_same_derivation(): void
    {
        $this->assertSame(Permission::REFUND_CASH, RefundPermissionPolicy::permissionFor(RefundChannel::CASH));
        $this->assertSame(Permission::REFUND_BANK, RefundPermissionPolicy::permissionFor(RefundChannel::BANK));

        // The panel derives its channel from the payment method with RefundChannel::defaultForMethod(),
        // then the permission with permissionFor() — the very pair the service uses.
        $this->assertSame(Permission::REFUND_CASH, RefundPermissionPolicy::permissionFor(RefundChannel::defaultForMethod('cash_on_delivery')));
        $this->assertSame(Permission::REFUND_BANK, RefundPermissionPolicy::permissionFor(RefundChannel::defaultForMethod('bank_transfer')));

        $source = file_get_contents(app_path('Filament/Resources/OrderResource.php'));
        $this->assertStringContainsString('RefundPermissionPolicy::permissionFor(RefundChannel::defaultForMethod(', $source);
        $this->assertStringNotContainsString("'cash_on_delivery' ? Permission::REFUND_CASH", $source, 'the old private derivation is gone');
    }

    public function test_the_policy_answers_for_the_acting_staff_member(): void
    {
        $this->actingAsRole('Manager');
        $policy = app(RefundPermissionPolicy::class);
        $this->assertTrue($policy->mayRecord(RefundChannel::CASH));
        $this->assertFalse($policy->mayRecord(RefundChannel::BANK));

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $this->assertFalse(app(RefundPermissionPolicy::class)->mayRecord(RefundChannel::CASH));
        $this->assertFalse(app(RefundPermissionPolicy::class)->mayRecord(RefundChannel::BANK));

        $this->actingAsRole('Administrator');
        $this->assertTrue(app(RefundPermissionPolicy::class)->mayRecord(RefundChannel::CASH));
        $this->assertTrue(app(RefundPermissionPolicy::class)->mayRecord(RefundChannel::BANK));
    }

    public function test_the_denial_is_translated_into_bulgarian(): void
    {
        app()->setLocale('bg');

        $this->assertStringContainsString('връщане в брой', (new RefundPermissionDeniedException(RefundChannel::CASH, Permission::REFUND_CASH))->getMessage());
        $this->assertStringContainsString('по банка', (new RefundPermissionDeniedException(RefundChannel::BANK, Permission::REFUND_BANK))->getMessage());
    }
}
