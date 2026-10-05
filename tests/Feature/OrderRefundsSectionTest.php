<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\Exceptions\PendingShippingInvariantBrokenException;
use App\Services\OrderRefundsReader;
use App\Services\OrderStatusChanger;
use App\Services\RefundStatusChanger;
use DateTimeImmutable;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use App\Settings\Contracts\SiteSettingsRepository;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use stdClass;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R2b (shipping-domain-design.md §7.2.5, §7.2.17): the refunds section of the order page,
 * its two actions, and the shipping row in the totals — through Filament/Livewire, on orders that
 * are built, settled and returned by the real services.
 */
class OrderRefundsSectionTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private function actingAsStaff(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(strtolower($roleName).'-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);
        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffPanelUser
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    /** A settled order (4 x 10.00) with one OWED refund of 2 units. */
    private function owedRefund(string $method = 'cash_on_delivery'): array
    {
        $order = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]], method: $method);
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 2, 'restock' => true]], $this->at());

        return ['order' => $order, 'refund' => $this->refundsOf($order['payment'])[0]];
    }

    private function page(string $orderId)
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId]);
    }

    private function action(string $name, string $refundId): TestAction
    {
        return TestAction::make($name)->schemaComponent('refund_actions_'.$refundId);
    }

    private function refundStatus(string $refundId): string
    {
        return (string) DB::table('payment_refunds')->where('id', $refundId)->value('status');
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    // --- the section -------------------------------------------------------------------------------------------------------

    public function test_the_section_lists_refunds_newest_first_with_their_states_and_breakdown(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $first] = $this->owedRefund();
        app(RefundStatusChanger::class)->markPaidOut($first->id(), new DateTimeImmutable('2026-09-27 10:00:00'), null, 'paid from the register', $this->at());
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]], $this->at(), null,
            new \App\Services\RefundRequest(shipping: null, deduction: $this->eur(100), deductionReason: 'box damaged'));
        $second = $this->refundsOf($order['payment'])[1];

        $this->page($order['orderId'])
            ->assertSeeInOrder(['Refund #'.$second->id(), 'Refund #'.$first->id()])
            ->assertSee('Owed')
            ->assertSee('Paid out')
            ->assertSee('paid from the register')
            ->assertSee('box damaged')
            ->assertSeeInOrder(['Goods', '10.00', 'Deduction', '1.00', 'Total', '9.00'])
            ->assertSeeInOrder(['Goods', '20.00', 'Total', '20.00']);
    }

    public function test_a_legacy_refund_with_no_breakdown_renders_sensibly(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);
        DB::table('payment_refunds')->insert([
            'payment_id' => $order['payment']->id(), 'order_id' => $order['orderId'], 'amount_minor' => 500, 'amount_currency' => 'EUR', 'channel' => 'cash',
            'goods_minor' => 500, 'status' => 'paid_out', 'paid_out_at' => '2026-09-01 10:00:00', 'paid_out_note' => OrderRefundsReader::LEGACY_NOTE,
            'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
        ]);

        $this->page($order['orderId'])
            ->assertOk()
            ->assertSee('Paid out (legacy)')
            ->assertSee('5.00')
            ->assertDontSee(OrderRefundsReader::LEGACY_NOTE, false);
    }

    public function test_an_order_without_refunds_shows_no_refunds_box_at_all(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $this->page($order['orderId'])
            ->assertOk()
            ->assertDontSee(__('orders.refunds.heading'))
            ->assertDontSee(__('orders.refunds.figures.paid_in'));
    }

    public function test_the_four_figures_follow_the_refunds_owed_then_paid_out_then_cancelled(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        $reader = app(OrderRefundsReader::class);
        $labels = ['Paid in', 'Refunded and paid out', 'Refunded, still owed', 'Still refundable'];

        // One OWED refund of 20.00 against a settled 40.00: paid in 40, paid out 0, owed 20, still refundable 20.
        $this->assertSame(['paid_in' => 4000, 'paid_out' => 0, 'owed' => 2000, 'still_refundable' => 2000], $reader->forOrder($order['orderId'], 'EUR')['figures']);
        $this->page($order['orderId'])->assertSeeInOrder([$labels[0], '40.00', $labels[1], '0.00', $labels[2], '20.00', $labels[3], '20.00']);

        // After paying out: the 20.00 moves from "owed" to "paid out"; still refundable is unchanged (it counts both).
        app(RefundStatusChanger::class)->markPaidOut($refund->id(), new DateTimeImmutable('2026-09-27 10:00:00'), null, null, $this->at());
        $this->assertSame(['paid_in' => 4000, 'paid_out' => 2000, 'owed' => 0, 'still_refundable' => 2000], $reader->forOrder($order['orderId'], 'EUR')['figures']);
        $this->page($order['orderId'])->assertSeeInOrder([$labels[0], '40.00', $labels[1], '20.00', $labels[2], '0.00', $labels[3], '20.00']);

        // A second refund, cancelled: it frees its room, so nothing of it is left in any figure.
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]], $this->at());
        $second = $this->refundsOf($order['payment'])[1];
        $this->assertSame(['paid_in' => 4000, 'paid_out' => 2000, 'owed' => 1000, 'still_refundable' => 1000], $reader->forOrder($order['orderId'], 'EUR')['figures']);
        app(RefundStatusChanger::class)->cancelOwed($second->id(), 'withdrawn', $this->at());
        $this->assertSame(['paid_in' => 4000, 'paid_out' => 2000, 'owed' => 0, 'still_refundable' => 2000], $reader->forOrder($order['orderId'], 'EUR')['figures']);
        $this->page($order['orderId'])->assertSeeInOrder([$labels[0], '40.00', $labels[1], '20.00', $labels[2], '0.00', $labels[3], '20.00', 'Cancelled', 'withdrawn']);
    }

    // --- Mark paid out ---------------------------------------------------------------------------------------------------------

    public function test_marking_paid_out_requires_a_reference_for_bank_and_not_for_cash_and_moves_the_refund(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $bankOrder, 'refund' => $bank] = $this->owedRefund('bank_transfer');
        ['order' => $cashOrder, 'refund' => $cash] = $this->owedRefund('cash_on_delivery');
        $when = now()->subHour()->format('Y-m-d H:i');

        $this->page($bankOrder['orderId'])
            ->callAction($this->action('mark_refund_paid_out', $bank->id()), data: ['paid_out_at' => $when, 'reference' => '', 'note' => null])
            ->assertHasActionErrors(['reference' => 'required']);
        $this->assertSame('owed', $this->refundStatus($bank->id()));

        $this->page($bankOrder['orderId'])
            ->callAction($this->action('mark_refund_paid_out', $bank->id()), data: ['paid_out_at' => $when, 'reference' => 'BANK-77', 'note' => 'paid in the app'])
            ->assertHasNoActionErrors();
        $this->assertSame('paid_out', $this->refundStatus($bank->id()));
        $this->assertSame('BANK-77', DB::table('payment_refunds')->where('id', $bank->id())->value('paid_out_reference'));

        $this->page($cashOrder['orderId'])
            ->callAction($this->action('mark_refund_paid_out', $cash->id()), data: ['paid_out_at' => $when, 'reference' => '', 'note' => null])
            ->assertHasNoActionErrors();
        $this->assertSame('paid_out', $this->refundStatus($cash->id()), 'cash needs no reference');
    }

    public function test_marking_paid_out_refuses_a_future_date(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $refund] = $this->owedRefund('cash_on_delivery');

        $this->page($order['orderId'])
            ->callAction($this->action('mark_refund_paid_out', $refund->id()), data: ['paid_out_at' => now()->addDays(2)->format('Y-m-d H:i'), 'reference' => null, 'note' => null])
            ->assertHasActionErrors(['paid_out_at']);

        $this->assertSame('owed', $this->refundStatus($refund->id()));
    }

    public function test_the_payout_time_is_entered_stored_and_shown_in_the_merchants_timezone(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $refund] = $this->owedRefund('cash_on_delivery');

        // The merchant is in Sofia (UTC+3 on this date); ApplyStoreTimezone sets it on every panel request, which
        // a Livewire test does not run, so it is set the same way here. The clock is frozen at 20:30 UTC = 23:30 local.
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        FilamentTimezone::set('Europe/Sofia');
        Carbon::setTestNow('2026-10-04 20:30:00');

        try {
            // The picker works in the merchant's timezone: what is typed is local time. 23:45 local is 20:45 UTC,
            // a quarter of an hour ahead of the frozen clock.
            $this->page($order['orderId'])
                ->callAction($this->action('mark_refund_paid_out', $refund->id()), data: ['paid_out_at' => '2026-10-04 23:45:00', 'reference' => null, 'note' => null])
                ->assertHasActionErrors(['paid_out_at']);
            $this->assertSame('owed', $this->refundStatus($refund->id()), '23:45 local is in the future');

            // The dialog opens on "now": the default is given in the app timezone (20:30) and Filament hydrates the
            // picker in the merchant's (23:30), which is what the form holds and the merchant sees.
            $opened = $this->page($order['orderId'])->mountAction($this->action('mark_refund_paid_out', $refund->id()));
            $this->assertSame('2026-10-04 23:30', $opened->instance()->mountedActions[0]['data']['paid_out_at'] ?? null);

            // 23:00 local is 20:00 UTC, half an hour in the past: accepted.
            $this->page($order['orderId'])
                ->callAction($this->action('mark_refund_paid_out', $refund->id()), data: ['paid_out_at' => '2026-10-04 23:00:00', 'reference' => null, 'note' => null])
                ->assertHasNoActionErrors();

            $this->assertSame('paid_out', $this->refundStatus($refund->id()));
            $this->assertSame('2026-10-04 20:00:00', (string) DB::table('payment_refunds')->where('id', $refund->id())->value('paid_out_at'), 'stored in UTC');

            // And shown back in the merchant's own time.
            $this->page($order['orderId'])->assertSee('Oct 4, 2026 23:00:00')->assertDontSee('Oct 4, 2026 20:00:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    // --- Cancel ----------------------------------------------------------------------------------------------------------------------

    public function test_cancelling_requires_a_reason_and_moves_the_refund_to_cancelled(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();

        $this->page($order['orderId'])
            ->callAction($this->action('cancel_refund', $refund->id()), data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame('owed', $this->refundStatus($refund->id()));

        $this->page($order['orderId'])
            ->callAction($this->action('cancel_refund', $refund->id()), data: ['reason' => 'customer withdrew the claim'])
            ->assertHasNoActionErrors();

        $this->assertSame('cancelled', $this->refundStatus($refund->id()));
        $this->assertSame('customer withdrew the claim', DB::table('payment_refunds')->where('id', $refund->id())->value('cancelled_reason'));
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund_reversal')->count(), 'the storno was appended');
    }

    // --- visibility --------------------------------------------------------------------------------------------------------------------

    public function test_both_actions_are_hidden_without_the_channels_permission(): void
    {
        // Recording needs the channel's permission too, so the refunds are made by an Administrator first.
        $this->actingAsStaff('Administrator');
        ['order' => $bankOrder, 'refund' => $bank] = $this->owedRefund('bank_transfer');
        ['order' => $cashOrder, 'refund' => $cash] = $this->owedRefund('cash_on_delivery');
        $mark = __('orders.refunds.mark_paid_out.label');
        $cancel = __('orders.refunds.cancel.label');

        // The Manager holds REFUND_CASH and not REFUND_BANK (staff-access-domain-design.md §3.1).
        $this->actingAsStaff('Manager');

        $this->page($bankOrder['orderId'])->assertSee('Refund #'.$bank->id())->assertDontSee($mark)->assertDontSee($cancel);
        $this->page($cashOrder['orderId'])
            ->assertSee($mark)
            ->assertSee($cancel)
            ->assertActionVisible($this->action('mark_refund_paid_out', $cash->id()))
            ->assertActionVisible($this->action('cancel_refund', $cash->id()));

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $this->page($cashOrder['orderId'])->assertSee('Refund #'.$cash->id())->assertDontSee($mark)->assertDontSee($cancel);
    }

    public function test_both_actions_are_hidden_on_a_refund_that_is_not_owed(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $paid] = $this->owedRefund();
        app(RefundStatusChanger::class)->markPaidOut($paid->id(), new DateTimeImmutable('2026-09-27 10:00:00'), null, null, $this->at());
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]], $this->at());
        $cancelled = $this->refundsOf($order['payment'])[1];
        app(RefundStatusChanger::class)->cancelOwed($cancelled->id(), 'withdrawn', $this->at());

        // Both refunds are listed, and neither offers an action: the buttons are for an OWED refund only.
        $this->page($order['orderId'])
            ->assertSee('Refund #'.$paid->id())
            ->assertSee('Refund #'.$cancelled->id())
            ->assertDontSee(__('orders.refunds.mark_paid_out.label'))
            ->assertDontSee(__('orders.refunds.cancel.label'));
    }

    // --- refusals become notices -----------------------------------------------------------------------------------------------------

    public function test_a_replay_with_a_changed_payload_is_shown_as_a_notice_not_a_500(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $one] = $this->owedRefund('cash_on_delivery');
        $this->changer()->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]], $this->at());
        $two = $this->refundsOf($order['payment'])[1];

        // The key 'dup' was already used, for another refund: the same key with other contents.
        app(RefundStatusChanger::class)->markPaidOut($one->id(), new DateTimeImmutable('2026-09-27 10:00:00'), null, null, $this->at(), 'dup');

        $this->page($order['orderId'])
            ->callAction($this->action('mark_refund_paid_out', $two->id()), data: ['paid_out_at' => now()->subHour()->format('Y-m-d H:i'), 'reference' => null, 'note' => null, 'operation_key' => 'dup']);

        $this->assertSame('owed', $this->refundStatus($two->id()), 'nothing was changed');
        $this->assertStringContainsString('already submitted', (string) $this->lastNotificationBody());
    }

    public function test_a_refusal_of_the_transition_itself_is_a_notice(): void
    {
        $this->actingAsStaff('Administrator');
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();
        // An OWED refund recorded before R1b has no link to its return: cancelling it is refused by name.
        DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'refund_owed')->update(['payment_refund_id' => null]);

        $this->page($order['orderId'])
            ->callAction($this->action('cancel_refund', $refund->id()), data: ['reason' => 'withdrawn']);

        $this->assertSame('owed', $this->refundStatus($refund->id()));
        $this->assertStringContainsString('cannot be cancelled automatically', (string) $this->lastNotificationBody());
    }

    public function test_a_broken_invariant_is_shown_as_a_plain_notice(): void
    {
        $this->actingAsStaff('Administrator');
        $order = OrderModel::query()->first() ?? OrderModel::query()->make(['id' => '1']);
        $method = new ReflectionMethod(OrderResource::class, 'runOrderAction');
        $method->setAccessible(true);

        $method->invoke(null, $order, new stdClass(), static function (): void {
            throw new PendingShippingInvariantBrokenException('1', '2', -700);
        }, 'unused');

        $this->assertStringContainsString('do not add up', (string) $this->lastNotificationBody());
    }

    public function test_the_texts_are_translated_into_bulgarian(): void
    {
        app()->setLocale('bg');

        $this->assertSame('Отбележи като изплатено', __('orders.refunds.mark_paid_out.label'));
        $this->assertSame('Още може да се възстанови', __('orders.refunds.figures.still_refundable'));
        $this->assertSame('Изплатено (старо)', __('orders.refunds.status.legacy_paid_out'));
        $this->assertSame('Доставка', __('orders.fields.shipping'));
        $this->assertStringContainsString('Стоките остават върнати', __('orders.refunds.cancel.warning'));
    }

    // --- shipping in the totals ----------------------------------------------------------------------------------------------------------

    public function test_the_totals_show_the_shipping_row_with_the_method_name_and_add_up(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000, 'discount' => 200]], shippingMinor: 350);
        $row = DB::table('orders')->where('id', $order['orderId'])->first();

        $this->assertSame(2000, (int) $row->subtotal_minor);
        $this->assertSame(200, (int) $row->discount_minor);
        $this->assertSame(350, (int) $row->shipping_minor);
        $this->assertSame((int) $row->subtotal_minor - (int) $row->discount_minor + (int) $row->shipping_minor, (int) $row->total_minor, 'the figures add up');

        $this->page($order['orderId'])
            ->assertSeeInOrder(['Subtotal', '20.00', 'Discount', '2.00', 'Shipping', '(Test courier)', '3.50', 'Total', '21.50']);
    }

    public function test_an_order_without_a_shipping_method_shows_zero_and_no_name(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $this->page($order['orderId'])
            ->assertSeeInOrder(['Shipping', '0.00', 'Total', '20.00'])
            ->assertDontSee('(Test courier)');
    }

    public function test_the_shipping_label_is_bulgarian_in_a_bulgarian_panel(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], shippingMinor: 350);
        app()->setLocale('bg');   // the web group applies the store locale to every panel request; a Livewire test skips that middleware

        $this->page($order['orderId'])->assertSee('Доставка');
    }
}
