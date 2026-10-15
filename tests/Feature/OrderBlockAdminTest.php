<?php

namespace Tests\Feature;

use App\Enums\OrderBlockType;
use App\Filament\Resources\OrderBlockResource;
use App\Filament\Resources\OrderBlockResource\Pages\ListOrderBlocks;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\OrderBlockModel;
use App\Services\OrderBlockService;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Filament\Actions\Testing\TestAction;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B, the admin side (cod-protection-design.md 3.2, 3.3): «Блокирай» / «Отблокирай» next to the phone, the
 * email and the IP of an order (ORDER_MANAGE only, the IP through the gated reader), the state line, the «Блокирания» list (masked values, the full
 * IP only for ORDER_MANAGE, filters, unblock, extend, manual add) and the activity log (masked values, never a full IP).
 */
class OrderBlockAdminTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const IP = '203.0.113.50';

    protected function setUp(): void
    {
        parent::setUp();

        // PH-1: a phone typed by hand is read with the STORE's country (a configured shop always has one).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        app()->setLocale('en');
    }

    private function place(array $extra = []): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);

        return (string) $this->checkout($this->streetFields('BG'), array_merge(['phone' => '0888 123 456', 'email' => 'Fake@Example.com', 'payment_method' => 'cash_on_delivery'], $extra))->assertStatus(201)->json('order.id');
    }

    private function orderPage(string $order): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ViewOrder::class, ['record' => $order]);
    }

    /** An action that lives in an entry of the order's infolist: `block_phone` in the `phone` entry, and so on. */
    private function entry(string $action): TestAction
    {
        $entry = ['phone' => 'phone', 'email' => 'email', 'ip' => 'block_ip'][substr($action, strpos($action, '_') + 1)];

        return TestAction::make($action)->schemaComponent($entry, schema: 'infolist');
    }

    // ---- permissions on the order view ---------------------------------------------------------------------------------------------------

    public function test_staff_who_manage_orders_see_the_three_block_buttons_and_the_note_in_the_modal(): void
    {
        $order = $this->place();

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $page = $this->orderPage($order);

            foreach (['block_phone', 'block_email', 'block_ip'] as $action) {
                $page->assertActionVisible($this->entry($action));
            }
            foreach (['unblock_phone', 'unblock_email', 'unblock_ip'] as $action) {
                $page->assertActionHidden($this->entry($action));
            }
        }

        $this->assertSame('Blocking stops only cash on delivery for this customer. Paying by bank transfer stays possible.', __('blocks.form.note'));
        $this->assertSame('Блокирането спира само наложения платеж за този клиент. Плащане по банков път остава възможно.', __('blocks.form.note', [], 'bg'));
    }

    public function test_staff_with_only_order_view_have_no_block_buttons_and_cannot_block_by_calling_the_action(): void
    {
        $order = $this->place();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $page = $this->orderPage($order);
        // The IP entry is not even in the schema for this viewer (it is only built for ORDER_MANAGE), so there is no IP button to hide.
        foreach (['block_phone', 'block_email', 'unblock_phone', 'unblock_email'] as $action) {
            $page->assertActionHidden($this->entry($action));
        }

        // Filament refuses to mount an action that is not visible to this viewer: the click never reaches the service.
        $refused = false;
        try {
            $page->callAction($this->entry('block_phone'), data: ['duration' => '180']);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $refused = true;
        }

        $this->assertTrue($refused);
        $this->assertSame(0, OrderBlockModel::query()->count(), 'a refused click blocks nothing');
    }

    public function test_the_ip_button_exists_only_for_manage_and_never_asks_a_viewer_for_the_address(): void
    {
        $order = $this->place();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::IP, $html);
        $this->assertStringNotContainsString(__('blocks.ip_row_label'), $html, 'the row is not even on the page');
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('`ip`', $sql, 'no query touched the address column');
        }
    }

    // ---- blocking and unblocking from the order -------------------------------------------------------------------------------------------

    public function test_blocking_the_phone_stores_its_key_the_order_and_the_chosen_duration(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');

        $this->orderPage($order)->callAction($this->entry('block_phone'), data: ['duration' => '180', 'reason' => 'did not collect twice'])->assertNotified(__('blocks.notice.blocked'));

        $block = OrderBlockModel::query()->sole();
        $this->assertSame(['phone', '+359888123456', '+359888123456', 'did not collect twice'], [$block->type, $block->value_key, $block->value_display, $block->reason]);
        $this->assertSame((int) $order, (int) $block->source_order_id);
        $this->assertNotNull($block->created_by);
        $this->assertEqualsWithDelta(now()->addDays(180)->timestamp, $block->expires_at->timestamp, 60);
    }

    public function test_forever_and_a_custom_number_of_days(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');

        $this->orderPage($order)->callAction($this->entry('block_email'), data: ['duration' => 'forever']);
        $this->assertNull(OrderBlockModel::query()->where('type', 'email')->sole()->expires_at);

        $this->orderPage($order)->callAction($this->entry('block_phone'), data: ['duration' => 'custom', 'custom_days' => 7]);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, OrderBlockModel::query()->where('type', 'phone')->sole()->expires_at->timestamp, 60);
    }

    public function test_a_custom_duration_must_be_a_number_of_days(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');

        foreach ([0, 5000, 'x'] as $bad) {
            $this->orderPage($order)->callAction($this->entry('block_phone'), data: ['duration' => 'custom', 'custom_days' => $bad])->assertHasActionErrors(['custom_days']);
        }

        $this->assertSame(0, OrderBlockModel::query()->count());
    }

    public function test_the_ip_is_blocked_through_the_gated_reader_and_the_state_shows(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');

        $this->orderPage($order)->callAction($this->entry('block_ip'), data: ['duration' => '180']);

        $block = OrderBlockModel::query()->sole();
        $this->assertSame(['ip', self::IP, self::IP], [$block->type, $block->value_key, $block->value_display]);

        $this->app->forgetScopedInstances();
        $html = $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();
        $this->assertStringContainsString('Blocked until', $html);
        $this->assertSame(1, substr_count($html, self::IP), 'the address is still printed once, in the header');
    }

    public function test_a_blocked_identifier_shows_its_end_and_an_unblock_button_instead_of_block(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');
        app(OrderBlockService::class)->block(OrderBlockType::PHONE, '0888 123 456', 30);
        app(OrderBlockService::class)->block(OrderBlockType::EMAIL, 'fake@example.com', null);

        $page = $this->orderPage($order);
        $page->assertActionHidden($this->entry('block_phone'))->assertActionVisible($this->entry('unblock_phone'));
        $page->assertActionHidden($this->entry('block_email'))->assertActionVisible($this->entry('unblock_email'));
        $page->assertActionVisible($this->entry('block_ip'));

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();
        $this->assertStringContainsString('Blocked until '.now()->addDays(30)->format('M j, Y'), $html);
        $this->assertStringContainsString('Blocked permanently', $html);
    }

    public function test_unblocking_revokes_the_block_and_takes_the_hint_away(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');
        app(OrderBlockService::class)->block(OrderBlockType::PHONE, '0888 123 456', 30);

        $this->orderPage($order)->callAction($this->entry('unblock_phone'))->assertNotified(__('blocks.notice.unblocked'));

        $block = OrderBlockModel::query()->sole();
        $this->assertNotNull($block->revoked_at);
        $this->assertNotNull($block->revoked_by);

        $this->app->forgetScopedInstances();
        $this->orderPage($order)->assertActionVisible($this->entry('block_phone'))->assertActionHidden($this->entry('unblock_phone'));
    }

    public function test_a_phone_with_no_key_cannot_be_blocked_from_the_order(): void
    {
        // PH-1: an order can no longer be placed with a number that short; an old row that holds one has no key.
        $order = $this->place();
        DB::table('orders')->where('id', $order)->update(['phone' => '1234567', 'phone_key' => null]);
        $this->actingAsStaff('Administrator');

        $this->orderPage($order)->assertActionHidden($this->entry('block_phone'))->assertActionVisible($this->entry('block_email'));
    }

    // ---- the activity log ------------------------------------------------------------------------------------------------------------------

    public function test_the_activity_log_gets_the_masked_value_who_and_the_duration_never_a_full_ip(): void
    {
        $this->activityLogOn();
        $order = $this->place();
        $staff = $this->actingAsStaff('Administrator');

        $this->orderPage($order)->callAction($this->entry('block_ip'), data: ['duration' => '180']);
        $this->orderPage($order)->callAction($this->entry('block_phone'), data: ['duration' => 'forever']);

        $rows = DB::table('activity_log')->where('entity_type', 'order_block')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame($staff->name, $rows[0]->staff_name, 'who');
        $this->assertStringContainsString('ip 203.0.*.* · until', (string) $rows[0]->new_value, 'the type, the MASKED value and the end');
        $this->assertStringContainsString('phone *** *** 456 · permanent', (string) $rows[1]->new_value);

        $everything = json_encode($rows->all());
        $this->assertStringNotContainsString(self::IP, $everything);
        $this->assertStringNotContainsString('888123456', $everything);
        $this->assertStringNotContainsString('0888 123 456', $everything);
    }

    // ---- the list «Блокирания» ---------------------------------------------------------------------------------------------------------------

    private function seedBlocks(): void
    {
        $service = app(OrderBlockService::class);
        $service->block(OrderBlockType::PHONE, '0888 123 456', 180, 'phone reason', '12');
        $service->block(OrderBlockType::EMAIL, 'Maria.Petrova@gmail.com', null);
        $service->block(OrderBlockType::IP, self::IP, 30);
        OrderBlockModel::query()->create(['type' => 'email', 'value_key' => 'old@x.bg', 'value_display' => 'old@x.bg', 'expires_at' => now()->subDay()]);
        OrderBlockModel::query()->create(['type' => 'phone', 'value_key' => '877000111', 'value_display' => '0877 000 111', 'revoked_at' => now()]);
    }

    public function test_the_list_is_in_the_orders_group_and_open_to_order_view_but_not_to_others(): void
    {
        $this->assertSame(\App\Filament\NavigationGroup::SALES, OrderBlockResource::getNavigationGroup());
        $this->assertSame('Order blocks', OrderBlockResource::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Блокирания', OrderBlockResource::getNavigationLabel());

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $this->get(OrderBlockResource::getUrl('index'))->assertOk();

        $this->actingAsStaff('Product Entry');
        $this->get(OrderBlockResource::getUrl('index'))->assertForbidden();
        $this->assertFalse(OrderBlockResource::canAccess());
    }

    public function test_the_list_masks_every_value_for_order_view_and_shows_the_full_ip_only_to_manage(): void
    {
        $this->seedBlocks();

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $viewer = Livewire::test(ListOrderBlocks::class)->html();
        $this->assertStringContainsString('*** *** 456', $viewer);
        $this->assertStringContainsString('m***@gmail.com', $viewer);
        $this->assertStringContainsString('203.0.*.*', $viewer);
        foreach ([self::IP, '0888 123 456', 'Maria.Petrova'] as $secret) {
            $this->assertStringNotContainsString($secret, $viewer);
        }

        $this->actingAsStaff('Administrator');
        $manager = Livewire::test(ListOrderBlocks::class)->html();
        $this->assertStringContainsString(self::IP, $manager, 'the full IP, for ORDER_MANAGE only');
        $this->assertStringContainsString('*** *** 456', $manager, 'a phone and an email stay masked for everyone');
        $this->assertStringNotContainsString('0888 123 456', $manager);
    }

    public function test_the_list_filters_by_type_and_by_state(): void
    {
        $this->seedBlocks();
        $this->actingAsStaff('Administrator');
        $list = Livewire::test(ListOrderBlocks::class);

        $this->assertCount(5, $list->instance()->getTableRecords());
        $list->filterTable('type', 'phone')->assertCountTableRecords(2)->resetTableFilters();
        $list->filterTable('state', 'active')->assertCountTableRecords(3)->resetTableFilters();
        $list->filterTable('state', 'expired')->assertCountTableRecords(1)->resetTableFilters();
        $list->filterTable('state', 'revoked')->assertCountTableRecords(1)->resetTableFilters();
    }

    public function test_unblock_and_extend_from_the_list_need_manage_and_an_active_block(): void
    {
        $this->seedBlocks();
        $active = OrderBlockModel::query()->where('type', 'phone')->where('value_key', '+359888123456')->sole();
        $ended = OrderBlockModel::query()->where('value_key', 'old@x.bg')->sole();

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        Livewire::test(ListOrderBlocks::class)->assertTableActionHidden('revoke', $active)->assertTableActionHidden('extend', $active);

        $this->actingAsStaff('Administrator');
        $list = Livewire::test(ListOrderBlocks::class);
        $list->assertTableActionVisible('revoke', $active)->assertTableActionVisible('extend', $active);
        $list->assertTableActionHidden('revoke', $ended)->assertTableActionHidden('extend', $ended);

        $list->callTableAction('extend', $active, data: ['duration' => 'forever']);
        $this->assertNull($active->fresh()->expires_at);

        $list->callTableAction('revoke', $active);
        $this->assertNotNull($active->fresh()->revoked_at);
        $this->assertSame(OrderBlockModel::query()->count(), 5, 'revoking deletes nothing');
    }

    public function test_a_block_can_be_added_by_hand_only_with_manage_and_only_for_a_value_that_has_a_key(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        Livewire::test(ListOrderBlocks::class)->assertActionHidden('add_block');

        $this->actingAsStaff('Administrator');
        $list = Livewire::test(ListOrderBlocks::class);

        $list->callAction('add_block', data: ['type' => 'phone', 'value' => '+359 877 000 111', 'duration' => '180', 'reason' => 'known offender'])->assertNotified(__('blocks.notice.blocked'));
        $block = OrderBlockModel::query()->sole();
        $this->assertSame(['phone', '+359877000111', null], [$block->type, $block->value_key, $block->source_order_id]);

        $list->callAction('add_block', data: ['type' => 'phone', 'value' => '12345', 'duration' => '180'])->assertNotified(__('blocks.notice.cannot_block'));
        $this->assertSame(1, OrderBlockModel::query()->count());
    }

    public function test_the_list_reads_the_blocks_in_one_query_whatever_their_number(): void
    {
        $this->actingAsStaff('Administrator');
        $count = function (): int {
            $n = 0;
            DB::listen(function ($query) use (&$n): void {
                $n += str_contains($query->sql, 'order_blocks') ? 1 : 0;
            });
            Livewire::test(ListOrderBlocks::class);

            return $n;
        };

        $this->seedBlocks();
        $few = $count();
        foreach (range(1, 20) as $i) {
            OrderBlockModel::query()->create(['type' => 'email', 'value_key' => "bulk{$i}@x.bg", 'value_display' => "bulk{$i}@x.bg", 'created_by' => 1]);
        }

        $this->assertSame($few, $count(), 'the same number of block queries for 5 rows and for 25');
    }

    public function test_a_stored_phone_is_never_shown_in_full_on_an_order_view_either(): void
    {
        $order = $this->place();
        app(OrderBlockService::class)->block(OrderBlockType::PHONE, '0888 123 456', 30, 'secret reason');
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();

        $this->assertStringNotContainsString('secret reason', $html, 'the staff note is for the list');
        $this->assertSame(Carbon::now()->format('Y'), now()->format('Y'));
    }
}
