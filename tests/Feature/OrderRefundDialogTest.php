<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\OrderStatusChanger;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Notifications\Livewire\Notifications;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 part 2 (shipping-domain-design.md §7.2.1, §7.2.4, §7.2.6, §7.2.11): the cancel / return
 * dialogs' money part, the facts panel, the announced day, and the money-only action — through the real
 * View page. Fixtures are the persisted orders of BuildsRefundableOrders (placed 2026-09-28 09:00 UTC,
 * 5 x 10.00, optional shipping, a settled payment); the store timezone is Europe/Sofia.
 */
class OrderRefundDialogTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
    }

    private function page(string $orderId): Testable
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId]);
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    /** The mounted action's schema (a protected method of the page; read through reflection). */
    private function dialog(Testable $component)
    {
        $instance = $component->instance();

        return (new ReflectionMethod($instance, 'getMountedActionSchema'))->invoke($instance);
    }

    /** @return list<string> the text of every VISIBLE Text component of the mounted dialog */
    private function texts(Testable $component): array
    {
        $texts = [];

        foreach ($this->dialog($component)->getFlatComponents(withActions: false) as $component) {
            if ($component instanceof Text) {
                $texts[] = trim(strip_tags(str_replace('<br>', "\n", (string) $component->getContent())));
            }
        }

        return $texts;
    }

    /** @return list<string> the state paths of every VISIBLE field of the mounted dialog */
    private function fields(Testable $component): array
    {
        return array_keys($this->dialog($component)->getFlatFields());
    }

    private function field(Testable $component, string $path)
    {
        return $this->dialog($component)->getFlatFields()[$path] ?? null;
    }

    /** A field's helper text (it is a Text in the field's below-content schema). */
    private function helper(Testable $component, string $path): string
    {
        $schema = $this->field($component, $path)?->getChildSchema('below_content');
        $out = '';

        foreach ($schema?->getComponents() ?? [] as $text) {
            if ($text instanceof Text) {
                $out .= (string) $text->getContent();
            }
        }

        return $out;
    }

    private function settledOrder(int $shippingMinor = 500, string $method = 'cash_on_delivery'): array
    {
        return $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: $shippingMinor, method: $method);
    }

    /** A pending (answered, unconfirmed) payment instead of a settled one. */
    private function pendingOrder(int $shippingMinor = 500): array
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: $shippingMinor, settle: false);
        $payment = Payment::create($order['orderId'], 'cash_on_delivery', $this->eur(5000 + $shippingMinor), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $order + ['pendingPayment' => $payment];
    }

    private function actingAsRole(string $roleName): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create('dlg-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffModel::find($staff->id()), 'staff');
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): void
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffModel::find($staff->id()), 'staff');
    }

    // ---- 1. settled payment ----------------------------------------------------------------------

    public function test_the_cancel_dialog_prefills_each_lines_goods_with_its_computed_share_and_shows_the_defaults(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('cancel');
        $data = $component->instance()->mountedActions[0]['data'];

        $this->assertSame('50.00', $data['goods'][$line], 'the computed share of the 5 remaining units');
        $this->assertSame('0', $data['shipping'], 'the shipping refund starts at 0');
        $this->assertSame('0', $data['deduction']);
        $this->assertSame('cash', $data['channel'], 'derived as before: cash on delivery -> cash');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $data['operation_key']);
        $this->assertContains('Refund total: 50.00 EUR', $this->texts($component), 'the live total: goods + shipping - deduction');
        $this->assertContains('Still refundable on this order: 55.00 EUR', $this->texts($component));
        $this->assertStringContainsString('Room left on this line: 50.00 EUR', $this->helper($component, "goods.{$line}"));
    }

    public function test_the_return_dialog_goods_follow_the_typed_quantity_and_the_total_follows_the_goods(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('record_return');
        $this->assertNull($component->instance()->mountedActions[0]['data']['goods'][$line] ?? null, 'blank until a quantity is typed');

        $component->set("mountedActions.0.data.quantity.{$line}", 2);

        $this->assertSame('20.00', $component->instance()->mountedActions[0]['data']['goods'][$line], '2 of 5 units: cumulative(2) - cumulative(0)');
        $this->assertContains('Refund total: 20.00 EUR', $this->texts($component));

        $component->set('mountedActions.0.data.shipping', '2,50')->set('mountedActions.0.data.deduction', '1');
        $this->assertContains('Refund total: 21.50 EUR', $this->texts($component), 'goods 20.00 + shipping 2.50 - deduction 1.00; comma or dot');
    }

    public function test_an_edit_within_the_caps_is_accepted_and_recorded_as_entered(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: [
            'quantity' => [$line => 2],
            'restock' => [$line => true],
            'goods' => [$line => '15,00'],
            'shipping' => '2,50',
            'deduction' => '1.00',
            'deduction_reason' => 'opened packaging',
            'channel' => 'cash',
            'operation_key' => 'dlg-edit-1',
        ])->assertHasNoActionErrors();

        $refund = DB::table('payment_refunds')->first();
        $this->assertNotNull($refund);
        $this->assertSame(1500, (int) $refund->goods_minor);
        $this->assertSame(250, (int) $refund->shipping_minor);
        $this->assertSame(100, (int) $refund->deduction_minor);
        $this->assertSame('opened packaging', $refund->deduction_reason);
        $this->assertSame(1650, (int) $refund->amount_minor, '15.00 + 2.50 - 1.00');
        $this->assertSame('cash', $refund->channel);

        $saleLine = DB::table('operational_sales_sale_lines')->where('type', 'refund')->first();
        $this->assertSame(1500, (int) $saleLine->actual_refund_amount_minor, 'the ledger records what the merchant entered');
        $this->assertSame(2000, (int) $saleLine->default_refund_amount_minor, 'and keeps the computed share');
    }

    public function test_a_blank_goods_box_is_the_computed_share_and_nothing_else_changes(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true]]);

        $this->assertSame(2000, (int) DB::table('payment_refunds')->value('amount_minor'));
        $this->assertSame(0, (int) DB::table('payment_refunds')->value('shipping_minor'));
    }

    public function test_an_amount_over_a_cap_is_refused_with_a_notice_and_nothing_is_written(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $stockBefore = $this->stockOf($order['variationIds'][0]);

        // The line: 2 units are worth 20.00 and the line holds 50.00 net paid — 60.00 breaks the per-line cap.
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'goods' => [$line => '60.00']]);
        $this->assertStringContainsString('50.00 EUR', (string) $this->lastNotificationBody());

        // The shipping: the order's shipping is 5.00.
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'shipping' => '6,00']);
        $this->assertStringContainsString('5.00 EUR', (string) $this->lastNotificationBody());

        $this->assertSame($stockBefore, $this->stockOf($order['variationIds'][0]));
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    public function test_the_dialog_warns_when_the_total_is_above_what_can_still_be_refunded(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('cancel');
        $this->assertNotContains('This is more than can still be refunded (55.00 EUR). The system will refuse it.', $this->texts($component));

        $component->set("mountedActions.0.data.goods.{$line}", '60');
        $this->assertContains('This is more than can still be refunded (55.00 EUR). The system will refuse it.', $this->texts($component));
    }

    public function test_the_shipping_notice_appears_while_shipping_is_not_included_and_never_adds_it(): void
    {
        $order = $this->settledOrder(500);
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('cancel');
        $this->assertContains('Shipping (5.00 EUR) is not included.', $this->texts($component));
        $this->assertSame('0', $component->instance()->mountedActions[0]['data']['shipping'], 'it is never prefilled');

        $component->set('mountedActions.0.data.shipping', '1,00');
        $this->assertNotContains('Shipping (5.00 EUR) is not included.', $this->texts($component));

        // An order with no shipping has nothing to say.
        $free = $this->settledOrder(0);
        $this->assertNotContains('Shipping (0.00 EUR) is not included.', $this->texts($this->page($free['orderId'])->mountAction('cancel')));

        app()->setLocale('bg');
        $this->assertContains('Доставката (5.00 EUR) не е включена.', $this->texts($this->page($order['orderId'])->mountAction('cancel')));
    }

    public function test_a_deduction_needs_its_reason(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('cancel');
        $this->assertNotContains('deduction_reason', $this->fields($component), 'hidden until there is a deduction');

        $component->set('mountedActions.0.data.deduction', '2');
        $this->assertContains('deduction_reason', $this->fields($component));

        $this->page($order['orderId'])->callAction('cancel', data: ['deduction' => '2', 'restock' => [$line => true]])->assertHasActionErrors(['deduction_reason']);
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_an_unreadable_amount_is_a_field_error_not_a_refund(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'shipping' => '1.2.3'])
            ->assertHasActionErrors(['shipping']);
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'goods' => [$line => 'ten']])
            ->assertHasActionErrors(["goods.{$line}"]);

        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    // ---- the channel -----------------------------------------------------------------------------

    public function test_the_channel_options_follow_the_staff_members_permissions(): void
    {
        $order = $this->settledOrder(500, 'bank_transfer');

        // An administrator may use both: the choice is shown, bank is the default for a bank transfer.
        $both = $this->page($order['orderId'])->mountAction('cancel');
        $this->assertContains('channel', $this->fields($both));
        $this->assertSame(['cash', 'bank'], array_keys($this->field($both, 'channel')->getOptions()));
        $this->assertSame('bank', $both->instance()->mountedActions[0]['data']['channel']);

        // A Manager holds REFUND_CASH only: nothing to choose (the box is hidden) and the only channel is used.
        $this->actingAsRole('Manager');
        $one = $this->page($order['orderId'])->mountAction('cancel');
        $this->assertNotContains('channel', $this->fields($one), 'hidden when only one channel is possible');
        $this->assertSame('cash', $one->instance()->mountedActions[0]['data']['channel'], 'but still submitted, and the default is the one allowed');

        $this->page($order['orderId'])->callAction('cancel', data: ['restock' => []]);

        $this->assertSame('cash', DB::table('payment_refunds')->value('channel'), 'a bank-transfer order, refunded in cash by a staff member who may only use cash');
    }

    public function test_staff_with_neither_channel_get_a_notice_and_no_submit_button(): void
    {
        $order = $this->settledOrder();
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);

        $component = $this->page($order['orderId'])->assertActionVisible('cancel')->assertActionVisible('record_return');
        $component->mountAction('cancel');

        $this->assertContains(
            'This order has been paid, and you have neither the "refund in cash" nor the "refund by bank" permission, so you cannot record this. Ask an administrator.',
            $this->texts($component),
        );
        $this->assertNull($component->instance()->getMountedAction()->getModalSubmitAction(), 'the dialog cannot submit');

        // Even a crafted submission reaches the service, which refuses it: nothing is recorded.
        $this->page($order['orderId'])->callAction('cancel', data: ['restock' => []]);
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame('shipped', DB::table('orders')->where('id', $order['orderId'])->value('status'));

        // The money-only action is not offered at all.
        $this->page($order['orderId'])->assertActionHidden('refund_money_only');

        // An order with nothing paid needs no channel: the submit button is there.
        $pending = $this->pendingOrder();
        $unpaid = $this->page($pending['orderId'])->mountAction('cancel');
        $this->assertNotNull($unpaid->instance()->getMountedAction()->getModalSubmitAction());
    }

    // ---- 2. pending payment ----------------------------------------------------------------------

    public function test_a_pending_payment_shows_read_only_goods_and_no_deduction_or_channel(): void
    {
        $order = $this->pendingOrder();
        [$line] = $order['saleLineIds'];

        $component = $this->page($order['orderId'])->mountAction('record_return');
        $component->set("mountedActions.0.data.quantity.{$line}", 2);

        $goods = $this->field($component, "goods.{$line}");
        $this->assertTrue($goods->isDisabled(), 'read-only: the computed share');
        $this->assertSame('20.00', $component->instance()->mountedActions[0]['data']['goods'][$line]);
        $this->assertNotContains('deduction', $this->fields($component));
        $this->assertNotContains('deduction_reason', $this->fields($component));
        $this->assertNotContains('channel', $this->fields($component));
        $this->assertContains('shipping', $this->fields($component), 'only the shipping reduction is editable');
        $this->assertStringContainsString('Shipping reduction', (string) $this->field($component, 'shipping')->getLabel());
        $this->assertStringContainsString('Room left: 5.00 EUR', $this->helper($component, 'shipping'));
        $this->assertContains('Nothing has been paid, so no money is refunded; the unpaid amount is reduced.', $this->texts($component));
    }

    public function test_a_cancel_of_a_pending_payment_has_no_shipping_field_because_everything_is_voided(): void
    {
        $order = $this->pendingOrder();

        $component = $this->page($order['orderId'])->mountAction('cancel');

        $this->assertNotContains('shipping', $this->fields($component));
        $this->assertNotContains('deduction', $this->fields($component));
        $this->assertNotContains('channel', $this->fields($component));
        $this->assertTrue($this->field($component, 'goods.'.$order['saleLineIds'][0])->isDisabled());
    }

    public function test_a_shipping_reduction_on_a_partial_return_of_a_pending_payment_is_accepted_and_reissues_the_remainder(): void
    {
        $order = $this->pendingOrder(500); // pending 55.00
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: [
            'quantity' => [$line => 2],
            'restock' => [$line => true],
            'shipping' => '1,00',
        ]);

        $payments = DB::table('payments')->where('order_id', $order['orderId'])->orderBy('id')->get();
        $this->assertCount(2, $payments, 'the old pending payment is voided and one new one issued');
        $this->assertNotNull($payments[0]->voided_at);
        $this->assertSame(3400, (int) $payments[1]->amount_minor, '55.00 - goods 20.00 - shipping reduction 1.00');
        $this->assertSame(0, DB::table('payment_refunds')->count(), 'nothing was paid, nothing is refunded');
    }

    // ---- 3. returns only: the announced day and the facts ----------------------------------------

    public function test_a_return_has_the_announced_day_and_the_facts_and_a_cancel_has_neither(): void
    {
        $order = $this->settledOrder();
        $this->actingAsAdministrator();

        $return = $this->page($order['orderId'])->mountAction('record_return');
        $cancel = $this->page($order['orderId'])->mountAction('cancel');

        $this->assertContains('announced_return_on', $this->fields($return));
        $this->assertNotContains('announced_return_on', $this->fields($cancel));
        $this->assertContains('Not marked as delivered.', $this->texts($return), 'never delivered: no numbers');
        $this->assertNotContains('Not marked as delivered.', $this->texts($cancel));

        $picker = $this->field($return, 'announced_return_on');
        $this->assertFalse($picker->hasTime(), 'a calendar day: no time component');
        $this->assertSame('Y-m-d', $picker->getFormat());
        $this->assertSame(app(StoreTimezone::class)->today(), $picker->getMaxDate(), 'today in the STORE timezone');
        $this->assertStringContainsString('no time needed', $this->helper($return, 'announced_return_on'));
    }

    public function test_the_facts_panel_shows_the_delivery_the_days_since_and_the_earlier_returns_as_plain_numbers(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $changer = app(OrderStatusChanger::class);
        $changer->deliver($order['orderId'], new DateTimeImmutable('2026-09-29 22:30:00', new DateTimeZone('UTC'))); // 30 Sep 01:30 in Sofia
        $changer->recordReturn($order['orderId'], [['originatingSaleLineId' => $line, 'quantityReturned' => 1, 'restock' => true]], new DateTimeImmutable('2026-10-02 10:00:00'), null, new \App\Services\RefundRequest(announcedReturnOn: '2026-10-01'));

        $texts = implode("\n", $this->texts($this->page($order['orderId'])->mountAction('record_return')));
        $daysSince = StoreTimezone::daysBetween('2026-09-30', app(StoreTimezone::class)->today());

        $this->assertStringContainsString("Delivered 2026-09-30 · days since delivery: {$daysSince}", $texts, 'the delivery day in the STORE timezone, and the count beside it');
        $this->assertStringContainsString('Earlier return: recorded 2026-10-02, announced 2026-10-01 (days after delivery: 2 recorded, 1 announced)', $texts);
        $this->assertDoesNotMatchRegularExpression('/late|overdue|window|expired/i', $texts, 'facts, never a verdict');
    }

    public function test_the_announced_day_travels_through_the_dialog_and_shows_in_the_history_row(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'announced_return_on' => '2026-09-28'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-09-28', DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'returned')->value('announced_return_on'));

        // A new request: the admin reader is scoped and memoises per request (OrderAdminReader), so a page
        // loaded after the write needs a fresh one — exactly what the redirect after the action gives.
        $this->app->forgetScopedInstances();
        $this->page($order['orderId'])->assertSee('Customer announced the return for: 2026-09-28');

        app()->setLocale('bg');
        $this->app->forgetScopedInstances();
        $this->page($order['orderId'])->assertSee('Клиентът обяви връщане за: 2026-09-28');
    }

    public function test_an_announced_day_in_the_future_is_refused_and_nothing_is_recorded(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $tomorrow = (new DateTimeImmutable(app(StoreTimezone::class)->today().' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

        // The picker's own maxDate (today in the store timezone) refuses it first, as a field error ...
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'announced_return_on' => $tomorrow])
            ->assertHasActionErrors(['announced_return_on']);

        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    public function test_the_service_refusal_of_an_impossible_announced_day_is_a_translated_notice(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        // The order was placed on 28 Sep: 27 Sep passes the picker (it is not in the future) and the SERVICE refuses it.
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'announced_return_on' => '2026-09-27']);

        $this->assertSame(__('orders.return_announced_date.before_placement'), $this->lastNotificationBody());
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    // ---- 4. the money-only action ----------------------------------------------------------------

    public function test_the_money_only_action_records_an_owed_refund_with_goods_zero(): void
    {
        $order = $this->settledOrder();

        $component = $this->page($order['orderId'])->mountAction('refund_money_only');
        $this->assertContains('Shipping room left: 5.00 EUR. Still refundable on this order: 55.00 EUR.', $this->texts($component));
        $component->set('mountedActions.0.data.shipping', '3,00')->set('mountedActions.0.data.adjustment', '2');
        $this->assertContains('Refund total: 5.00 EUR', $this->texts($component));

        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '3,00', 'adjustment' => '2.00', 'reason' => 'goodwill', 'channel' => 'cash'])
            ->assertHasNoActionErrors();

        $refund = DB::table('payment_refunds')->first();
        $this->assertSame('owed', $refund->status);
        $this->assertSame(0, (int) $refund->goods_minor);
        $this->assertSame(300, (int) $refund->shipping_minor);
        $this->assertSame(200, (int) $refund->adjustment_minor);
        $this->assertSame('goodwill', $refund->reason);
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'no stock moved');
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    public function test_the_money_only_action_works_on_a_delivered_and_on_a_cancelled_order(): void
    {
        $changer = app(OrderStatusChanger::class);

        $delivered = $this->settledOrder();
        $changer->deliver($delivered['orderId'], $this->at());
        $this->page($delivered['orderId'])->assertActionVisible('refund_money_only')
            ->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '0', 'reason' => 'late parcel', 'channel' => 'cash']);
        $this->assertSame(1, DB::table('payment_refunds')->where('order_id', $delivered['orderId'])->count());

        $cancelled = $this->settledOrder();
        $changer->cancel($cancelled['orderId'], $this->at());
        $this->assertSame('cancelled', DB::table('orders')->where('id', $cancelled['orderId'])->value('status'));
        $this->page($cancelled['orderId'])->assertActionVisible('refund_money_only')
            ->callAction('refund_money_only', data: ['shipping' => '5', 'adjustment' => '0', 'reason' => 'kept shipping by mistake', 'channel' => 'cash']);
        $this->assertSame(2, DB::table('payment_refunds')->where('order_id', $cancelled['orderId'])->count(), 'the cancel\'s goods refund and the shipping on top');
    }

    public function test_the_money_only_action_is_hidden_on_a_pending_payment_and_without_a_channel_permission(): void
    {
        $pending = $this->pendingOrder();
        $this->page($pending['orderId'])->assertActionHidden('refund_money_only');

        $none = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);
        $this->page($none['orderId'])->assertActionHidden('refund_money_only');

        $settled = $this->settledOrder();
        $this->page($settled['orderId'])->assertActionVisible('refund_money_only');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        $this->page($settled['orderId'])->assertActionHidden('refund_money_only');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::REFUND_CASH]);
        $this->page($settled['orderId'])->assertActionHidden('refund_money_only');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_BANK]);
        $this->page($settled['orderId'])->assertActionVisible('refund_money_only');
    }

    public function test_the_money_only_action_needs_a_reason_and_its_refusals_are_notices(): void
    {
        $order = $this->settledOrder();

        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '0', 'reason' => '', 'channel' => 'cash'])
            ->assertHasActionErrors(['reason']);

        // Nothing to refund: refused by the service, shown as a notice.
        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '0', 'adjustment' => '0', 'reason' => 'x', 'channel' => 'cash']);
        $this->assertSame(__('orders.money_only_refund.nothing_to_refund'), $this->lastNotificationBody());

        // More than was paid: the cap's sentence.
        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '0', 'adjustment' => '999', 'reason' => 'x', 'channel' => 'cash']);
        $this->assertStringContainsString('55.00 EUR', (string) $this->lastNotificationBody());

        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => 'abc', 'adjustment' => '0', 'reason' => 'x', 'channel' => 'cash'])
            ->assertHasActionErrors(['shipping']);

        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    // ---- 5. behaviour ----------------------------------------------------------------------------

    public function test_a_double_submit_of_the_money_only_dialog_records_once(): void
    {
        $order = $this->settledOrder();
        $data = ['shipping' => '2', 'adjustment' => '0', 'reason' => 'goodwill', 'channel' => 'cash', 'operation_key' => 'one-opening'];

        $this->page($order['orderId'])->callAction('refund_money_only', data: $data);
        $this->page($order['orderId'])->callAction('refund_money_only', data: $data);

        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(['refund_owed'], $this->eventTypesOf($order['orderId']));
    }

    public function test_a_double_submit_of_the_return_dialog_with_the_new_fields_records_once(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $data = ['quantity' => [$line => 2], 'restock' => [$line => true], 'goods' => [$line => '18,00'], 'shipping' => '1', 'channel' => 'cash', 'announced_return_on' => '2026-09-28', 'operation_key' => 'one-opening-return'];

        $this->page($order['orderId'])->callAction('record_return', data: $data);
        $this->page($order['orderId'])->callAction('record_return', data: $data);

        $this->assertSame(1, DB::table('payment_refunds')->count());
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'the units came back once');
    }

    public function test_the_labels_exist_in_both_languages(): void
    {
        foreach (['en', 'bg'] as $locale) {
            app()->setLocale($locale);

            foreach (['orders.money_only.label', 'orders.refund_dialog.shipping_not_included', 'orders.refund_dialog.announced_help', 'orders.history.announced_on', 'orders.return_announced_date.in_future'] as $key) {
                $this->assertNotSame($key, __($key), "{$locale}: {$key}");
            }
        }

        app()->setLocale('bg');
        $this->assertSame('Връщане само на сума', __('orders.money_only.label'));
    }
}
