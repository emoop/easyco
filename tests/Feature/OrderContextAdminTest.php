<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Services\OrderContextReader;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\InvoiceSettings;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Order context, stage C2-A, the admin side (order-context-design.md 7.1, 11.1, 11.4): the order view's Origin and Invoice sections, and the
 * client address — the full value ONLY for ORDER_MANAGE, through a separate gated reader method (no query and no value in memory without it),
 * and the anonymised date and network for everyone with ORDER_VIEW.
 */
class OrderContextAdminTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const IP = '203.0.113.5';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        app()->setLocale('en');
    }

    /** @param array<string, mixed> $invoice */
    private function place(array $invoice = []): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');

        return (string) $this->checkout($this->streetFields('BG'), $invoice === [] ? [] : ['invoice' => $invoice])->assertStatus(201)->json('order.id');
    }

    private function page(string $order): string
    {
        return $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();
    }

    /** The text of one infolist section: from its heading to the next section's heading (the order view lays them out in a column). */
    private function section(string $html, string $title, string $next): string
    {
        preg_match('/<h2[^>]*>\s*'.preg_quote($title, '/').'\s*<\/h2>/', $html, $start, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($start, "the {$title} section is on the page");
        $rest = substr($html, $start[0][1]);
        $end = preg_match('/<h2[^>]*>\s*'.preg_quote($next, '/').'\s*<\/h2>/', $rest, $stop, PREG_OFFSET_CAPTURE) === 1 ? $stop[0][1] : strlen($rest);

        return substr($rest, 0, $end);
    }

    /** @return list<string> */
    private function queriesWhile(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $work();

        return $queries;
    }

    // ---- the client address (section 7.1) ------------------------------------------------------------------------------------------

    public function test_staff_who_manage_orders_see_the_full_address_in_the_header(): void
    {
        $order = $this->place();

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $html = $this->page($order);

            $this->assertStringContainsString('IP '.self::IP, $html, "{$role} holds ORDER_MANAGE");
            $this->assertSame(1, substr_count($html, self::IP), 'once: in the header, never repeated in a card');
            $this->assertStringContainsString(__('orders.context.ip_kept'), $html, 'and the origin section says what state it is in');
        }
    }

    public function test_staff_with_only_order_view_see_no_address_and_cause_no_query_that_reads_it(): void
    {
        $order = $this->place();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $html = '';
        $queries = $this->queriesWhile(function () use ($order, &$html): void {
            $html = $this->page($order);
        });

        $this->assertStringNotContainsString(self::IP, $html, 'the full address is not on the page');
        $this->assertStringNotContainsString('IP '.self::IP, $html);
        $this->assertStringContainsString(__('orders.context.ip_restricted'), $html, 'the page says it is restricted rather than looking empty');

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('`ip`', $sql, 'no query touches the address column for a staff member without ORDER_MANAGE');
        }
        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'order_attributions')), 'the origin itself was read (the check is not vacuous)');
    }

    public function test_the_manager_does_cause_the_one_gated_query(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Manager');

        $queries = $this->queriesWhile(fn () => $this->page($order));

        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'select `ip` from `order_attributions`')), 'one narrow read, remembered for the rest of the request');
    }

    public function test_the_reader_answers_nothing_without_the_permission_and_asks_the_database_nothing(): void
    {
        $order = $this->place();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $queries = $this->queriesWhile(function () use ($order, &$address): void {
            $address = app(OrderContextReader::class)->fullClientAddress($order);
        });

        $this->assertNull($address);
        $this->assertSame([], array_filter($queries, fn (string $sql): bool => str_contains($sql, 'order_attributions')), 'no query at all');
        $this->assertFalse(property_exists(app(OrderContextReader::class)->forOrder($order), 'customerIp'), 'the view object has no place for a full address');

        $this->actingAsStaff('Administrator');
        $this->assertSame(self::IP, app()->make(OrderContextReader::class)->fullClientAddress($order));
    }

    public function test_once_anonymised_everyone_with_order_view_sees_the_date_and_the_network(): void
    {
        $order = $this->place();
        DB::table('order_attributions')->update(['captured_at' => now()->subDays(60)]);
        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        foreach ([fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW]), fn () => $this->actingAsStaff('Administrator')] as $actAs) {
            $actAs();
            $html = $this->page($order);

            $this->assertStringContainsString('203.0.113.0', $html, 'the anonymised network');
            $this->assertStringContainsString(__('orders.context.ip_anonymised_short'), $html);
            $this->assertStringNotContainsString(self::IP, $html, 'the full address is gone for everybody');
            $this->assertMatchesRegularExpression('/'.preg_quote(__('orders.context.fields.client_address'), '/').'.{0,900}?'.preg_quote(substr(__('orders.context.ip_anonymised', ['date' => 'X', 'network' => 'Y']), 0, 10), '/').'/s', $html);
        }
    }

    // ---- the Origin section (section 11.1) -----------------------------------------------------------------------------------------

    public function test_the_origin_section_says_the_visit_is_not_recorded_yet_and_shows_the_request_facts(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');
        $html = $this->page($order);

        $this->assertStringContainsString(__('orders.context.reason_not_recorded_yet'), $html);
        $this->assertStringContainsString(__('orders.attribution_source_options.unknown'), $html, 'the source, through the enum label');
        $this->assertStringContainsString(__('orders.device_type_options.desktop'), $html, 'the device class, derived from the user agent');
        $this->assertStringContainsString('Strict (consent first) — Analytics not asked — nothing stored on the visitor&#039;s device', $html, 'the consent snapshot as one sentence');
        $this->assertStringNotContainsString('>organic<', $html);
    }

    public function test_the_bulgarian_reason_line_is_the_one_the_owner_wrote(): void
    {
        app()->setLocale('bg');

        $this->assertStringContainsString('Произходът на посещението още не се записва', __('orders.context.reason_not_recorded_yet'));
    }

    public function test_an_order_placed_before_context_capture_says_so_and_never_invents_a_direct_visit(): void
    {
        $order = $this->place();
        DB::table('order_attributions')->where('order_id', $order)->delete();
        $this->actingAsStaff('Administrator');
        $html = $this->page($order);

        $this->assertStringContainsString(__('orders.context.reason_predates'), $html);
        $this->assertStringNotContainsString(__('orders.attribution_source_options.direct'), $html);
    }

    // ---- the Invoice section (section 11.4) ----------------------------------------------------------------------------------------

    public function test_the_invoice_section_shows_the_request_with_the_configured_label_and_the_rule_version(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(InvoiceSettings::RULES_KEY, InvoiceSettings::encode(InvoiceSettings::normalizeRows([
            ['country' => 'BG', 'tax_id_label' => 'ЕИК / Булстат', 'representative_label' => 'МОЛ', 'registration_number_label' => 'ДДС номер'],
        ])));
        $settings->set(InvoiceSettings::RULES_VERSION_KEY, '4');
        $order = $this->place([
            'recipient_type' => 'company', 'legal_name' => 'Acme OOD', 'tax_id' => '123456789', 'company_registration_number' => 'BG123456789',
            'representative' => 'Ivan Ivanov', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => '1 Main St', 'email' => 'accountant@example.com',
        ]);
        $this->actingAsStaff('Administrator');
        $html = $this->page($order);
        $invoice = $this->section($html, __('orders.sections.invoice'), __('orders.sections.origin'));

        foreach (['Acme OOD', '123456789', 'BG123456789', 'Ivan Ivanov', 'accountant@example.com', '1 Main St, 1000 Sofia, BG', 'Company'] as $value) {
            $this->assertStringContainsString($value, $invoice);
        }
        foreach (['ЕИК / Булстат', 'ДДС номер', 'МОЛ'] as $label) {
            $this->assertStringContainsString($label, $invoice, 'the label the merchant configured for that country, not the column name');
        }
        $this->assertMatchesRegularExpression('/'.preg_quote(__('orders.context.fields.rule_version'), '/').'.{0,600}?>\s*4\s*</s', $invoice);
        $this->assertStringContainsString(__('orders.yes'), $invoice);
    }

    public function test_an_order_with_no_invoice_request_says_so_and_an_old_order_says_n_a(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');
        $html = $this->page($order);
        $invoice = $this->section($html, __('orders.sections.invoice'), __('orders.sections.origin'));

        $this->assertStringContainsString(__('orders.context.invoice_not_requested'), $invoice);
        $this->assertStringContainsString('n/a', $invoice, 'the legal name and address are not recorded');

        DB::table('order_attributions')->where('order_id', $order)->delete();
        $this->app->forgetScopedInstances(); // one request per process in production; the test makes two
        $html = $this->page($order);
        $invoice = $this->section($html, __('orders.sections.invoice'), __('orders.sections.origin'));

        $this->assertStringNotContainsString(__('orders.context.invoice_not_requested'), $invoice, 'an order that predates the capture may or may not have asked: it is not "no"');
    }

    public function test_the_context_reader_makes_two_reads_per_order_and_the_page_adds_no_per_row_work(): void
    {
        $order = $this->place();
        $this->actingAsStaff('Administrator');
        $reader = app(OrderContextReader::class);

        $queries = $this->queriesWhile(function () use ($reader, $order): void {
            $reader->forOrder($order);
            $reader->forOrder($order);
        });

        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'order_attributions') || str_contains($sql, 'order_invoice_details')), 'the origin and the invoice, once each');
    }
}
