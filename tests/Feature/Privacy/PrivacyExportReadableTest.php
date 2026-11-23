<?php

namespace Tests\Feature\Privacy;

use App\Privacy\PrivacyExportBuilder;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\HookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * ACC-4f: the readable HTML page of the export shows words, not codes (the admin's own Bulgarian labels, whatever the shop locale), `Name: Value` lines for the variants and `Y-m-d H:i UTC` dates.
 * The JSON keeps the raw codes and the ISO dates; only `attributes` changed shape (a list of name/value, no ids).
 */
class PrivacyExportReadableTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const SOLD = [
        ['definitionId' => '2', 'definitionCode' => 'roundtrip-size-1787518060', 'definitionName' => 'Size', 'valueId' => '7', 'value' => 'XXS'],
        ['definitionId' => '3', 'definitionCode' => 'roundtrip-color-1787518060', 'definitionName' => 'Color', 'valueId' => '9', 'value' => 'Red'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegionOrders();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        app(HookRegistry::class)->clear(PrivacyExportBuilder::FILTER);
    }

    protected function tearDown(): void
    {
        app(HookRegistry::class)->clear(PrivacyExportBuilder::FILTER);
        parent::tearDown();
    }

    /** @return array{html: string, json: string} */
    private function build(array $orderUpdate = []): array
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'me@example.com', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'recipient_email' => 'mum@example.com'])->assertStatus(201);
        $order = DB::table('orders')->orderByDesc('id')->first();
        DB::table('operational_sales_sale_lines')->where('transaction_id', $order->transaction_id)->update(['sold_attributes' => json_encode(self::SOLD)]);
        DB::table('orders')->where('id', $order->id)->update($orderUpdate + ['status' => 'refunded']);
        DB::table('order_events')->insert(['order_id' => $order->id, 'type' => 'status_changed', 'from_status' => 'placed', 'to_status' => 'delivered', 'occurred_at' => '2026-09-28 06:13:41']);
        DB::table('order_invoice_details')->insert([
            'order_id' => $order->id, 'requested_at' => now(), 'recipient_type' => 'company', 'legal_name' => 'Ivanov Ltd', 'country' => 'BG', 'city' => 'Plovdiv',
            'postal_code' => '4000', 'address_line_1' => 'Main 5', 'invoice_email' => 'a@ivanov.example', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return app(PrivacyExportBuilder::class)->build('me@example.com');
    }

    /** ★ */
    public function test_two_variants_are_two_name_value_lines_and_no_ids_or_json_reach_the_page(): void
    {
        $files = $this->build();

        $this->assertStringContainsString('Size: XXS<br>Color: Red', $files['html']);
        foreach (['valueId', 'definitionId', 'definitionCode', '{"value"', 'roundtrip-', '&quot;value&quot;'] as $noise) {
            $this->assertStringNotContainsString($noise, $files['html'], $noise);
        }
    }

    /** ★ */
    public function test_the_json_attributes_are_a_list_of_name_and_value_only(): void
    {
        $files = $this->build();
        $line = json_decode($files['json'], true, 512, JSON_THROW_ON_ERROR)['sections']['orders']['data'][0]['lines'][0];

        $this->assertSame([['name' => 'Size', 'value' => 'XXS'], ['name' => 'Color', 'value' => 'Red']], $line['attributes']);
        $this->assertStringNotContainsString('definitionCode', $files['json']);
    }

    /** ★ */
    public function test_codes_are_shown_as_the_admins_bulgarian_labels_whatever_the_shop_locale(): void
    {
        app()->setLocale('en');
        $files = $this->build();
        $html = $files['html'];

        $this->assertStringContainsString('>Върната<', $html, 'the order status refunded');
        $this->assertStringContainsString('>Наложен платеж<', $html, 'the payment method');
        $this->assertStringContainsString('>Адрес<', $html, 'the delivery type');
        $this->assertStringContainsString('>Смяна на статус<', $html, 'the event type');
        $this->assertStringContainsString('>Приета<', $html, 'the from status');
        $this->assertStringContainsString('>Доставена<', $html, 'the to status');
        $this->assertStringContainsString('>Фирма<', $html, 'the invoice recipient');
        foreach (['>refunded<', '>cash_on_delivery<', '>street_address<', '>status_changed<', '>placed<', '>delivered<', '>company<'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw);
        }

        $order = json_decode($files['json'], true, 512, JSON_THROW_ON_ERROR)['sections']['orders']['data'][0];
        $this->assertSame('refunded', $order['status'], 'the JSON keeps the raw codes');
        $this->assertSame('cash_on_delivery', $order['payment_method']);
        $this->assertSame('status_changed', $order['history'][0]['type']);
    }

    public function test_an_unknown_code_shows_as_it_is_and_never_throws(): void
    {
        $files = $this->build(['status' => 'mystery_status']);

        $this->assertStringContainsString('>mystery_status<', $files['html']);
    }

    /** ★ */
    public function test_dates_read_as_date_time_utc_in_the_page_and_stay_iso_in_the_json(): void
    {
        $files = $this->build();

        $this->assertStringContainsString('2026-09-28 06:13 UTC', $files['html']);
        $this->assertSame(0, preg_match('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $files['html']), 'no T and no offset in the page');
        $this->assertStringNotContainsString('+00:00', $files['html']);
        $this->assertStringContainsString('2026-09-28T06:13:41+00:00', $files['json']);
    }
}
