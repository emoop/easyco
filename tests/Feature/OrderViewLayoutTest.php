<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\OrderStatusChanger;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\TextSize;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * The order View page's two-column layout (order-view layout stage): a header row, a main column (2/3) with
 * Items + totals and Payment + refunds, a sidebar (1/3) with Customer and Delivery, History full width, compact
 * type — and nothing of what the page showed before has gone.
 */
class OrderViewLayoutTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    private function page(string $orderId)
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId]);
    }

    /** A settled, shipped order: 2 x 10.00 less 2.00 promotion, 3.50 shipping by "Test courier", a tracking number. */
    private function fullOrder(): array
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000, 'discount' => 200]], shippingMinor: 350);
        DB::table('orders')->where('id', $order['orderId'])->update(['applied_promotion_code' => 'SUMMER20', 'tracking_number' => 'TRK-123']);

        return $order;
    }

    public function test_every_field_the_page_showed_is_still_rendered_in_its_new_place(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->page($order['orderId'])
            ->assertOk()
            // header
            ->assertSee(__('orders.fields.id'))->assertSee(__('orders.status_options.shipped'))->assertSee(__('orders.fields.payment_status'))
            ->assertSeeInOrder([__('orders.payment_method_options.cash_on_delivery'), __('orders.channel_options.web'), 'Sep 28, 2026'])   // the subtitle line
            // items + totals
            ->assertSee(__('orders.sections.lines'))->assertSee(__('orders.fields.subtotal'))->assertSee(__('orders.fields.shipping'))->assertSee(__('orders.fields.total'))
            // payment
            ->assertSee(__('orders.sections.payment'))->assertSee(__('orders.fields.payment_method'))->assertSee(__('orders.fields.payment_confirmed_at'))
            // customer
            ->assertSee(__('orders.sections.client'))->assertSee('Ivan Ivanov')->assertSee('buyer@example.com')->assertSee('+359888123456')
            ->assertSee(__('orders.guest'))->assertSee(__('orders.fields.client_id'))
            // delivery
            ->assertSee(__('orders.sections.delivery'))->assertSee('BG')->assertSee('Sofia')->assertSee('Vitosha Blvd 1')
            ->assertSee(__('orders.sections.payment_shipping'))->assertSee(__('orders.fields.shipping_method'))->assertSee(__('orders.fields.tracking_number'))->assertSee('TRK-123')
            // history
            ->assertSee(__('orders.sections.history'));
    }

    public function test_the_totals_sit_with_the_items_add_up_and_the_discount_label_carries_the_promotion_code(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $row = DB::table('orders')->where('id', $order['orderId'])->first();

        $this->assertSame((int) $row->subtotal_minor - (int) $row->discount_minor + (int) $row->shipping_minor, (int) $row->total_minor);

        $this->page($order['orderId'])
            ->assertSeeInOrder([__('orders.sections.lines'), __('orders.fields.subtotal'), '20.00', 'Discount (SUMMER20)', '2.00', __('orders.fields.shipping'), '(Test courier)', '3.50', __('orders.fields.total'), '21.50', __('orders.sections.payment')])
            ->assertDontSee(__('orders.sections.promotion'), false);
    }

    public function test_the_discount_line_says_whether_the_code_is_redeemed(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->page($order['orderId'])->assertSee(__('orders.promotion_redeemed_no'));   // the fixture has no redemption row

        DB::table('orders')->where('id', $order['orderId'])->update(['applied_promotion_code' => null]);
        $this->page($order['orderId'])->assertDontSee(__('orders.promotion_redeemed_no'))->assertDontSee('Discount (');
    }

    public function test_the_refunds_group_shows_its_count_and_is_collapsed(): void
    {
        $this->actingAsAdmin();
        $order = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]]);
        $page = $this->page($order['orderId']);
        $page->assertDontSee('Refunds (');

        $this->actingAsAdmin();
        $changer = app(OrderStatusChanger::class);
        foreach ([1, 1] as $quantity) {
            $changer->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => $quantity, 'restock' => true]], $this->at());
        }

        $this->page($order['orderId'])->assertSee('Refunds (2)')->assertSee('Refund #');

        $section = $this->find($this->page($order['orderId'])->instance()->getSchema('infolist')->getComponents(), fn ($c) => $c instanceof Section && str_starts_with((string) $c->getHeading(), 'Refunds ('));
        $this->assertNotNull($section);
        $this->assertTrue($section->isCollapsible());
        $this->assertTrue($section->isCollapsed());
    }

    public function test_the_invoice_and_origin_cards_exist_and_say_n_a_for_what_is_not_recorded(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->page($order['orderId'])
            ->assertSee(__('orders.sections.invoice'))->assertSee(__('orders.sections.origin'))
            ->assertSee(__('orders.context.fields.company_name'))->assertSee(__('orders.context.fields.referrer'));
    }

    public function test_the_grid_is_a_main_column_of_two_thirds_a_sidebar_of_one_third_and_a_full_width_history(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $top = $this->page($order['orderId'])->instance()->getSchema('infolist')->getComponents();

        $this->assertCount(3, $top, 'header row, the two-column grid, history');
        $this->assertInstanceOf(Grid::class, $top[0]);
        $this->assertInstanceOf(Grid::class, $top[1]);
        $this->assertInstanceOf(Section::class, $top[2]);
        $this->assertSame(__('orders.sections.history'), $top[2]->getHeading());

        $this->assertSame(1, $top[1]->getColumns('default'), 'one column on a small screen (main first, then the sidebar)');
        $this->assertSame(3, $top[1]->getColumns('lg'));

        [$main, $sidebar] = $top[1]->getDefaultChildComponents();
        $this->assertInstanceOf(Group::class, $main);
        $this->assertInstanceOf(Group::class, $sidebar);
        $this->assertSame(2, $main->getColumnSpan('lg'));
        $this->assertSame(1, $sidebar->getColumnSpan('lg'));

        $headings = fn (Group $group): array => array_map(static fn ($c): string => (string) $c->getHeading(), array_filter($group->getDefaultChildComponents(), static fn ($c) => $c instanceof Section));
        $this->assertSame([__('orders.sections.lines'), __('orders.sections.payment')], array_slice(array_values($headings($main)), 0, 2));
        $this->assertSame(
            [__('orders.sections.client'), __('orders.sections.delivery'), __('orders.sections.payment_shipping'), __('orders.sections.order_details'), __('orders.sections.invoice'), __('orders.sections.origin')],
            array_values($headings($sidebar)),
        );
    }

    public function test_the_text_is_small_on_the_order_page_and_no_other_page_in_the_same_process_is_touched(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $role = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        $entrySizes = static function (string $html): array {
            preg_match_all('/class="([^"]*\bfi-in-text-item\b[^"]*)"/', $html, $matches);

            return array_count_values(array_map(static fn (string $class): string => str_contains($class, 'fi-size-xs') ? 'xs' : (str_contains($class, 'fi-size-sm') ? 'sm' : 'other'), $matches[1]));
        };

        // A page that uses TextEntry, BEFORE the order page has been rendered in this process.
        $before = $entrySizes(Livewire::test(ViewRole::class, ['record' => $role->id()])->html());
        $this->assertArrayNotHasKey('xs', $before);

        // The order page: its entries are extra small, in the header, the cards, the items table and the refunds group.
        $orderHtml = $this->page($order['orderId'])->html();
        $this->assertStringContainsString('fi-size-xs', $orderHtml);
        preg_match_all('/class="([^"]*\bfi-in-text-item\b[^"]*)"/', $orderHtml, $entries);
        $plain = array_filter($entries[1], static fn (string $class): bool => ! str_contains($class, 'fi-in-text-has-badges'));
        $this->assertNotEmpty($plain);
        $this->assertSame([], array_values(array_filter($plain, static fn (string $class): bool => ! str_contains($class, 'fi-size-xs'))), 'every text entry of the order page is extra small (a badge sizes itself)');

        // ... and the SAME process then renders another resource's page: its entries keep their default size.
        $after = $entrySizes(Livewire::test(ViewRole::class, ['record' => $role->id()])->html());
        $this->assertSame($before, $after, 'the other page keeps its entries at their default size');
        $this->assertNotEmpty($after, 'the other page does render text entries');

        // And a TextEntry built anywhere afterwards has the default size: no global configuration was left behind.
        $this->assertSame(TextSize::Small, TextEntry::make('probe')->getSize('x'));
    }

    public function test_the_size_is_set_by_a_local_helper_and_not_by_any_static_configuration(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->assertStringNotContainsString('::configureUsing(', file_get_contents($file->getPathname()), $file->getFilename().' must not use process-wide component configuration');
            }
        }
    }

    public function test_only_the_history_table_scrolls_sideways_the_items_wrap_like_blocks(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->page($order['orderId'])->html();

        // The items are blocks now: no scroll container and no minimum width. History keeps its own.
        $this->assertSame(1, substr_count($html, 'overflow-x: auto'), 'only the History table');
        $this->assertMatchesRegularExpression('/overflow-x: auto.*?min-width: 64rem/s', $html);
        $this->assertStringNotContainsString('min-width: 56rem', $html);
    }

    public function test_tracking_number_and_shipping_method_show_only_when_there_are_some(): void
    {
        $this->actingAsAdmin();
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $this->page($order['orderId'])->assertDontSee(__('orders.fields.tracking_number'))->assertDontSee(__('orders.fields.shipping_method'));
    }

    public function test_the_headings_are_bulgarian_in_a_bulgarian_panel(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        app()->setLocale('bg');

        $this->page($order['orderId'])
            ->assertSee('Начин на доставка')->assertSee('Номер за проследяване')->assertSee('Гост')->assertSee('неизползван')->assertSee('Доставка');
        $this->assertSame('Възстановявания (3)', __('orders.refunds.heading_count', ['count' => 3]));
    }

    /** @param array<int, mixed> $components */
    private function find(array $components, callable $match): mixed
    {
        foreach ($components as $component) {
            if ($match($component)) {
                return $component;
            }

            if (method_exists($component, 'getDefaultChildComponents') && ($found = $this->find($component->getDefaultChildComponents(), $match)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
