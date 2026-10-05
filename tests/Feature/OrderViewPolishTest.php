<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\ProductResource;
use App\Filament\StaffPanelUser;
use App\Services\OrderContextReader;
use App\Services\OrderContextView;
use App\Services\OrderStatusChanger;
use App\Services\RefundStatusChanger;
use DateTimeImmutable;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * The order View page's visual polish (existing data only): two levels of text, the items as blocks, the money summary,
 * the sidebar cards, the header subtitle, and the order-context fields that read "n/a" until their stages are built.
 */
class OrderViewPolishTest extends TestCase
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

    private function html(string $orderId): string
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
    }

    /** A settled, shipped order: 2 x 10.00 less 2.00 promotion, 3.50 shipping by "Test courier". */
    private function fullOrder(): array
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000, 'discount' => 200]], shippingMinor: 350);
        DB::table('orders')->where('id', $order['orderId'])->update(['applied_promotion_code' => 'SUMMER20', 'tracking_number' => 'TRK-123']);

        return $order;
    }

    /** The money summary table, alone. */
    private function moneySummary(string $html): string
    {
        $start = strpos($html, '<table style="margin-inline-start');
        $this->assertNotFalse($start, 'the money summary must be there');

        return substr($html, $start, strpos($html, '</table>', $start) - $start);
    }

    private function itemBlocks(string $html): array
    {
        preg_match_all('#<li class="fi-in-repeatable-item[^"]*">(.*?)</li>#s', substr($html, 0, strpos($html, '<table style="margin-inline-start')), $m);

        return $m[1];
    }

    // --- the items ----------------------------------------------------------------------------------------------------------

    public function test_each_item_links_to_its_product_edit_page_and_shows_the_sku_and_the_unit_price(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $productId = (string) DB::table('catalog_variations')->where('id', $order['variationIds'][0])->value('product_id');

        $block = $this->itemBlocks($this->html($order['orderId']))[0];

        $this->assertStringContainsString('href="'.e(ProductResource::getUrl('edit', ['record' => $productId])).'"', $block);
        $this->assertStringContainsString('target="_blank"', $block);
        $this->assertStringContainsString('rel="noopener noreferrer"', $block);
        $this->assertStringContainsString('SKU-', $block, 'the SKU');
        $this->assertStringContainsString('10.00 € × 2', $block, 'the unit price × quantity');
        $this->assertStringContainsString('Discount: -2.00 €', $block, 'the per-line discount');
        $this->assertStringContainsString('18.00 €', $block, 'the line total');
    }

    public function test_units_returned_or_removed_stay_visible_on_the_item(): void
    {
        $this->actingAsAdmin();
        $order = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]]);
        app(OrderStatusChanger::class)->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true]], $this->at());

        $this->assertStringContainsString(__('orders.line_labels.returned_or_removed', ['count' => 1]), $this->itemBlocks($this->html($order['orderId']))[0]);
    }

    public function test_the_items_have_no_minimum_width_and_history_keeps_its_own(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);
        $items = substr($html, 0, strpos($html, '<table style="margin-inline-start'));
        $afterItems = substr($html, strpos($html, '<table style="margin-inline-start'));

        $this->assertStringNotContainsString('min-width: 5', $items);
        $this->assertStringNotContainsString('overflow-x', $items);
        $this->assertStringContainsString('min-width: 64rem', $afterItems, 'History keeps its own');
    }

    // --- the money summary ---------------------------------------------------------------------------------------------------

    public function test_the_money_summary_adds_up_and_shows_the_code_and_the_method(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $summary = strip_tags($this->moneySummary($this->html($order['orderId'])));
        $row = DB::table('orders')->where('id', $order['orderId'])->first();

        $this->assertSame((int) $row->subtotal_minor - (int) $row->discount_minor + (int) $row->shipping_minor, (int) $row->total_minor);
        foreach (['Subtotal', '20.00', 'Discount (SUMMER20)', 'not redeemed', '-2.00', 'Shipping (Test courier)', '3.50', 'Total', '21.50'] as $part) {
            $this->assertStringContainsString($part, $summary);
        }
    }

    public function test_paid_refunded_and_owed_appear_only_when_they_are_relevant(): void
    {
        $this->actingAsAdmin();

        // No settled payment, no refund: no paid / refunded rows.
        $unpaid = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], settle: false);
        $summary = strip_tags($this->moneySummary($this->html($unpaid['orderId'])));
        $this->assertStringNotContainsString(__('orders.money.paid'), $summary);
        $this->assertStringNotContainsString(__('orders.money.refunded'), $summary);

        // A settled payment, no refund: paid and refunded (0.00), no "owed".
        $settled = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]]);
        $summary = strip_tags($this->moneySummary($this->html($settled['orderId'])));
        $this->assertStringContainsString(__('orders.money.paid'), $summary);
        $this->assertStringContainsString('40.00', $summary);
        $this->assertStringContainsString(__('orders.money.refunded'), $summary);
        $this->assertStringNotContainsString(__('orders.money.refund_owed'), $summary);

        // An OWED refund of 20.00: the owed row appears; after the payout it is the refunded row that holds the 20.00.
        app(OrderStatusChanger::class)->recordReturn($settled['orderId'], [['originatingSaleLineId' => $settled['saleLineIds'][0], 'quantityReturned' => 2, 'restock' => true]], $this->at());
        $summary = preg_replace('/\s+/', '', strip_tags($this->moneySummary($this->html($settled['orderId']))));
        $this->assertStringContainsString(str_replace(' ', '', __('orders.money.refund_owed')).'20.00', $summary);
        $this->assertStringContainsString(str_replace(' ', '', __('orders.money.refunded')).'0.00', $summary);

        app(RefundStatusChanger::class)->markPaidOut((string) $this->refundsOf($settled['payment'])[0]->id(), new DateTimeImmutable('2026-09-27 10:00:00'), null, null, $this->at());
        $summary = preg_replace('/\s+/', '', strip_tags($this->moneySummary($this->html($settled['orderId']))));
        $this->assertStringNotContainsString(str_replace(' ', '', __('orders.money.refund_owed')), $summary, 'a zero owed figure is not shown');
        $this->assertStringContainsString(str_replace(' ', '', __('orders.money.refunded')).'20.00', $summary);
    }

    // --- the sidebar and the header ------------------------------------------------------------------------------------------

    public function test_the_sidebar_cards_show_their_fields_as_label_value_pairs(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        foreach (['buyer@example.com', '+359888123456', 'Vitosha Blvd 1', 'Test courier · 3.50', 'TRK-123'] as $value) {
            $this->assertStringContainsString($value, $html);
        }

        // Inline labels: the label and its value sit on one line.
        $this->assertGreaterThanOrEqual(10, substr_count($html, 'fi-in-entry-has-inline-label'));
    }

    public function test_the_header_has_one_muted_subtitle_line_with_method_channel_date_and_ip(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        $this->assertMatchesRegularExpression('/'.preg_quote(__('orders.payment_method_options.cash_on_delivery'), '/').' · '.preg_quote(__('orders.channel_options.web'), '/').' · Sep 28, 2026 \d\d:\d\d · IP n\/a/u', $html);
    }

    public function test_primary_text_keeps_its_style_and_secondary_text_is_smaller_and_muted(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        // The subtitle (secondary) carries the muted style and the gray colour class...
        $this->assertMatchesRegularExpression('/<div class="fi-in-text-item[^"]*fi-color-gray[^"]*"[^>]*font-size: 0\.6875rem[^>]*>\s*[^<]*· IP/s', $html);
        // ...the item details are muted too...
        $this->assertStringContainsString('font-size: 0.6875rem; opacity: 0.65', $html);
        // ...while the customer's name (primary) has no such style.
        preg_match('#<div class="fi-in-text-item[^"]*"([^>]*)>\s*Ivan Ivanov#s', $html, $name);
        $this->assertNotEmpty($name, 'the customer name entry');
        $this->assertStringNotContainsString('font-size', $name[1]);
        $this->assertStringNotContainsString('fi-color-gray', $name[0]);
    }

    // --- the order-context fields -------------------------------------------------------------------------------------------

    public function test_every_order_context_field_says_n_a_for_an_order_today(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        foreach (['visitor_id', 'terms_accepted', 'confirmation_requested', 'call_before_shipping', 'company_name', 'vat_number', 'billing_address', 'source_type', 'campaign', 'landing_page', 'referrer'] as $field) {
            $label = __('orders.context.fields.'.$field);
            $this->assertMatchesRegularExpression('/'.preg_quote(e($label), '/').'.{0,900}?n\/a/s', $html, "{$label} must read n/a");
        }

        // The IP is shown in the header subtitle only, and not recorded reads n/a there.
        $this->assertStringContainsString('IP n/a', $html);

        // Not recorded is never "No": none of the yes/no fields shows "No".
        $details = substr($html, strpos($html, __('orders.sections.order_details')), 1500);
        $this->assertStringNotContainsString('>No<', $details);
        $this->assertStringNotContainsString('>Yes<', $details);
    }

    public function test_the_visitor_id_is_in_the_origin_card_shortened_with_the_full_value_as_tooltip_and_copy(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $full = '3f2b8c1e-9d4a-4e7b-a1c0-5b6d7e8f9a01';

        $this->app->instance(OrderContextReader::class, new class($full) extends OrderContextReader {
            public function __construct(private readonly string $id)
            {
            }

            public function forOrder(string $orderId): OrderContextView
            {
                return new OrderContextView(customerIp: '203.0.113.9', visitorId: $this->id);
            }
        });

        $html = $this->html($order['orderId']);

        // Shortened to the first UUID group + an ellipsis, in the Origin card — not in the Customer card.
        $this->assertStringContainsString('3f2b8c1e…', $html);
        $label = e(__('orders.context.fields.visitor_id'));
        $this->assertSame(1, substr_count($html, $label), 'one visitor id label on the page');
        $this->assertGreaterThan(strpos($html, __('orders.sections.origin')), strpos($html, $label), 'it is inside the Origin card');
        $this->assertLessThan(strpos($html, __('orders.sections.delivery')), strpos($html, e(__('orders.sections.client'))));
        $customerCard = substr($html, strpos($html, e(__('orders.sections.client'))), strpos($html, __('orders.sections.delivery')) - strpos($html, e(__('orders.sections.client'))));
        $this->assertStringNotContainsString($label, $customerCard);

        // The FULL value is the tooltip and what the copy button copies (at least twice in the markup), never the visible text.
        $this->assertGreaterThanOrEqual(2, substr_count($html, $full));
        $this->assertMatchesRegularExpression('/x-tooltip[^>]*'.preg_quote($full, '/').'/s', $html);
        $visible = strip_tags(substr($html, strpos($html, $label), 900));
        $this->assertStringNotContainsString($full, $visible);
    }

    public function test_the_visitor_id_says_n_a_without_tooltip_or_copy_when_there_is_none(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);
        $label = e(__('orders.context.fields.visitor_id'));
        $entry = substr($html, strpos($html, $label), 1200);

        $this->assertStringContainsString('n/a', $entry);
        $this->assertStringNotContainsString('…', substr($entry, 0, strpos($entry, __('orders.context.fields.source_type')) ?: 600));
    }

    public function test_the_ip_appears_once_in_the_header_subtitle_and_no_other_field_moved(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->app->instance(OrderContextReader::class, new class extends OrderContextReader {
            public function forOrder(string $orderId): OrderContextView
            {
                return new OrderContextView(customerIp: '203.0.113.9', visitorId: '3f2b8c1e-9d4a-4e7b-a1c0-5b6d7e8f9a01');
            }
        });
        $html = $this->html($order['orderId']);

        $this->assertSame(1, substr_count($html, '203.0.113.9'), 'the IP appears once');
        $this->assertStringContainsString('IP 203.0.113.9', $html);
        $this->assertLessThan(strpos($html, e(__('orders.sections.client'))), strpos($html, '203.0.113.9'), 'and it is in the header, above every card');
        $this->assertSame(0, substr_count($html, e(__('orders.context.fields.customer_ip'))), 'no IP address label in any card');

        // The Customer card keeps exactly its other fields, in order.
        $customer = substr($html, strpos($html, e(__('orders.sections.client'))), strpos($html, __('orders.sections.delivery')) - strpos($html, e(__('orders.sections.client'))));
        $this->assertSame(0, preg_match('/'.preg_quote(__('orders.context.fields.visitor_id'), '/').'/', $customer));
        $previous = -1;
        foreach (['Ivan Ivanov', 'buyer@example.com', '+359888123456', __('orders.guest'), __('orders.fields.client_id')] as $value) {
            $position = strpos($customer, $value);
            $this->assertNotFalse($position, "{$value} stays in the Customer card");
            $this->assertGreaterThan($previous, $position);
            $previous = $position;
        }

        // The Origin card keeps its four fields after the visitor id.
        $origin = substr($html, strpos($html, __('orders.sections.origin')));
        $previous = -1;
        foreach (['visitor_id', 'source_type', 'campaign', 'landing_page', 'referrer'] as $field) {
            $position = strpos($origin, e(__('orders.context.fields.'.$field)));
            $this->assertNotFalse($position);
            $this->assertGreaterThan($previous, $position);
            $previous = $position;
        }
    }

    public function test_label_value_pairs_of_the_sidebar_wrap_instead_of_overlapping(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        // Every pair is a wrapping flex row: a long label never runs into its value; the value drops below it.
        $pairs = substr_count($html, 'display: flex; flex-wrap: wrap; column-gap: 0.75rem; row-gap: 0.125rem; overflow-wrap: anywhere');
        $this->assertGreaterThanOrEqual(15, $pairs);
    }

    public function test_the_context_cards_exist_and_the_origin_card_is_collapsed(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();
        $html = $this->html($order['orderId']);

        foreach (['order_details', 'invoice', 'origin'] as $section) {
            $this->assertStringContainsString(__('orders.sections.'.$section), $html);
        }

        $found = null;
        $walk = function (array $components) use (&$walk, &$found): void {
            foreach ($components as $c) {
                if ($c instanceof \Filament\Schemas\Components\Section && $c->getHeading() === __('orders.sections.origin')) {
                    $found = $c;
                }
                if (method_exists($c, 'getDefaultChildComponents')) {
                    $walk($c->getDefaultChildComponents());
                }
            }
        };
        $walk(Livewire::test(ViewOrder::class, ['record' => $order['orderId']])->instance()->getSchema('infolist')->getComponents());

        $this->assertNotNull($found);
        $this->assertTrue($found->isCollapsible());
        $this->assertTrue($found->isCollapsed());
    }

    public function test_the_page_reads_these_fields_only_through_the_order_context_reader(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->app->instance(OrderContextReader::class, new class extends OrderContextReader {
            public function forOrder(string $orderId): OrderContextView
            {
                return new OrderContextView(
                    customerIp: '203.0.113.9', visitorId: 'vis-777',
                    termsAccepted: true, confirmationRequested: false, callBeforeShipping: null,
                    invoiceCompanyName: 'Acme Ltd', invoiceVatNumber: 'BG123456789', invoiceBillingAddress: '1 Main St, Sofia',
                    originSourceType: 'organic', originCampaign: 'autumn-sale', originLandingPage: '/shoes', originReferrer: 'google.com',
                );
            }
        });

        $html = $this->html($order['orderId']);

        foreach (['203.0.113.9', 'vis-777', 'Acme Ltd', 'BG123456789', '1 Main St, Sofia', 'organic', 'autumn-sale', '/shoes', 'google.com'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringContainsString('IP 203.0.113.9', $html, 'the subtitle shows the IP too');

        // true / false are Yes / No (recorded), null stays n/a (not recorded).
        $this->assertMatchesRegularExpression('/'.preg_quote(e(__('orders.context.fields.terms_accepted')), '/').'.{0,600}?'.__('orders.yes').'/s', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote(e(__('orders.context.fields.confirmation_requested')), '/').'.{0,600}?'.__('orders.no').'/s', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote(e(__('orders.context.fields.call_before_shipping')), '/').'.{0,600}?n\/a/s', $html);
    }

    public function test_the_reader_makes_no_query_and_returns_nothing_recorded_today(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $view = app(OrderContextReader::class)->forOrder('1');

        $this->assertSame(0, $queries);
        $this->assertEquals(OrderContextView::notRecorded(), $view);
        foreach ((array) $view as $value) {
            $this->assertNull($value);
        }
    }

    public function test_n_a_is_translated_and_there_is_no_tax_line_anywhere(): void
    {
        $this->actingAsAdmin();
        $order = $this->fullOrder();

        $this->assertSame('n/a', __('orders.context.not_recorded', [], 'en'));
        $this->assertSame('н/д', __('orders.context.not_recorded', [], 'bg'));

        app()->setLocale('bg');
        $html = $this->html($order['orderId']);
        $this->assertStringContainsString('н/д', $html);
        $this->assertStringContainsString('Детайли на поръчката', $html);
        $this->assertStringContainsString('Фактура', $html);
        $this->assertStringContainsString('Произход', $html);

        app()->setLocale('en');
        $this->assertDoesNotMatchRegularExpression('/\bVAT\s*(amount|total|\d)|\btax\b/i', strip_tags($this->moneySummary($this->html($order['orderId']))));
    }
}
