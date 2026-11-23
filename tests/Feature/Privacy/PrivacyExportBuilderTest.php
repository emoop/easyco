<?php

namespace Tests\Feature\Privacy;

use App\Privacy\PrivacyExportBuilder;
use App\Settings\Contracts\SiteSettingsRepository;
use Carbon\Carbon;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * ACC-4c (acc-4-gdpr-design.md §4): the content of the personal-data export. Real orders through the checkout; the assertions are on the two files the builder returns.
 */
class PrivacyExportBuilderTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const SECRETS = ['STAFFNOTE-77', 'EVENTREASON-77', 'STAFFNAME-77', 'BLOCKREASON-77'];

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

    /** a real guest order of $email; returns its id */
    private function order(string $email = 'me@example.com', array $extra = []): string
    {
        $this->checkout($this->streetFields('BG'), $extra + ['email' => $email, 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'recipient_email' => 'mum@example.com'])->assertStatus(201);

        return (string) DB::table('orders')->max('id');
    }

    /** everything staff-only that must never reach the export, planted around the order and the email */
    private function plantStaffOnlyData(string $orderId, string $email = 'me@example.com'): void
    {
        DB::table('customer_notes')->insert(['email_key' => $email, 'body' => 'STAFFNOTE-77', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_events')->insert(['order_id' => $orderId, 'type' => 'note_added', 'reason' => 'EVENTREASON-77', 'staff_name' => 'STAFFNAME-77', 'occurred_at' => now()]);
        DB::table('order_events')->insert(['order_id' => $orderId, 'type' => 'status_changed', 'from_status' => 'placed', 'to_status' => 'confirmed', 'reason' => 'EVENTREASON-77', 'staff_name' => 'STAFFNAME-77', 'occurred_at' => now()]);
        DB::table('order_blocks')->insert(['type' => 'email', 'value_key' => $email, 'value_display' => $email, 'reason' => 'BLOCKREASON-77', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function decoded(array $files): array
    {
        return json_decode($files['json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function account(string $email): int
    {
        return (int) DB::table('accounts')->insertGetId(['email' => $email, 'password' => 'x', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_every_section_is_present_with_the_data_of_the_person(): void
    {
        $accountId = $this->account('me@example.com');
        DB::table('addresses')->insert([
            'account_id' => $accountId, 'delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888111111',
            'country' => 'BG', 'city' => 'Plovdiv', 'postal_code' => '4000', 'address_line_1' => 'Saved street 9', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->order('me@example.com', ['customer_note' => 'ring twice']);
        DB::table('orders')->where('id', $id)->update(['marketing_consent' => true, 'marketing_consent_text' => 'I want the newsletter']);
        DB::table('order_attributions')->where('order_id', $id)->update(['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (X11)']);
        DB::table('order_invoice_details')->insert([
            'order_id' => $id, 'requested_at' => now(), 'recipient_type' => 'company', 'legal_name' => 'Ivanov Ltd', 'tax_id' => 'BG123456789', 'country' => 'BG', 'city' => 'Plovdiv',
            'postal_code' => '4000', 'address_line_1' => 'Main 5', 'invoice_email' => 'accounts@ivanov.example', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $files = app(PrivacyExportBuilder::class)->build('Me@Example.com');
        $data = $this->decoded($files);

        $this->assertSame(['account', 'addresses', 'orders', 'consents', 'lists'], array_keys($data['sections']));
        $this->assertSame('me@example.com', $data['sections']['account']['data']['email']);
        $this->assertSame('Saved street 9', $data['sections']['addresses']['data'][0]['address_line_1']);
        $this->assertSame([], $data['sections']['lists']['data'], 'no lists exist yet: the section is there, hook-fed');

        $order = $data['sections']['orders']['data'][0];
        $this->assertSame($id, $order['number']);
        $this->assertSame('mum@example.com', $order['recipient_email']);
        $this->assertSame('ring twice', $order['customer_note']);
        $this->assertSame('cash_on_delivery', $order['payment_method']);
        $this->assertSame('EUR', $order['currency']);
        $this->assertSame('Ivan', $order['delivery']['recipient_first_name']);
        $this->assertSame('Vitosha Blvd 1', $order['delivery']['address_line_1']);
        $this->assertSame('Ivanov Ltd', $order['invoice']['legal_name']);
        $this->assertSame('203.0.113.7', $order['attribution']['ip']);
        $this->assertSame('Mozilla/5.0 (X11)', $order['attribution']['user_agent']);
        $this->assertCount(1, $order['lines']);
        $this->assertSame(1, $order['lines'][0]['quantity']);
        $this->assertSame('10.00', $order['lines'][0]['unit_price']);
        $this->assertSame('10.00', $order['amounts']['subtotal']);

        $consent = $data['sections']['consents']['data'][0];
        $this->assertTrue($consent['marketing_consent']);
        $this->assertSame('I want the newsletter', $consent['marketing_consent_text']);
        $this->assertSame($id, $consent['order']);

        $this->assertStringContainsString('lang="bg"', $files['html']);
        $this->assertStringContainsString('Saved street 9', $files['html']);
        $this->assertStringContainsString('I want the newsletter', $files['html']);
    }

    public function test_ip_and_user_agent_are_absent_once_the_attribution_no_longer_keeps_them(): void
    {
        $id = $this->order();
        DB::table('order_attributions')->where('order_id', $id)->update(['ip' => null, 'user_agent' => null]);

        $order = $this->decoded(app(PrivacyExportBuilder::class)->build('me@example.com'))['sections']['orders']['data'][0];

        $this->assertArrayNotHasKey('attribution', $order);
    }

    public function test_the_orders_cross_the_chunk_boundary_each_exactly_once_in_order(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->order('me@example.com');
        }

        // chunk 2 with 5 orders = chunks of 2, 2, 1: a boundary crossed twice (the default chunk of 100 never crosses one here)
        $builder = new PrivacyExportBuilder(2);
        $orders = $this->decoded($builder->build('me@example.com'))['sections']['orders']['data'];

        $this->assertSame($ids, array_column($orders, 'number'), 'all five, once each, in id order');
        $this->assertSame($this->decoded(app(PrivacyExportBuilder::class)->build('me@example.com'))['sections']['orders']['data'], $orders, 'the chunk size changes nothing in the result');
        $this->assertSame(PrivacyExportBuilder::CHUNK, 100);
    }

    public function test_staff_notes_event_reasons_staff_names_and_block_reasons_never_appear(): void
    {
        $id = $this->order();
        $this->plantStaffOnlyData($id);

        $files = app(PrivacyExportBuilder::class)->build('me@example.com');

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $files['html'], "{$secret} leaked into the html");
            $this->assertStringNotContainsString($secret, $files['json'], "{$secret} leaked into the json");
        }

        $history = $this->decoded($files)['sections']['orders']['data'][0]['history'] ?? [];
        $types = array_column($history, 'type');
        $this->assertContains('status_changed', $types, 'the status a customer could see is exported');
        $this->assertNotContains('note_added', $types, 'a staff note event is not');
    }

    public function test_script_in_customer_text_is_escaped_in_the_html_and_the_json_keeps_the_raw_text(): void
    {
        $this->order('me@example.com', ['customer_note' => '<script>alert(1)</script>', 'recipient_first_name' => '"><img src=x onerror=alert(2)>']);

        $files = app(PrivacyExportBuilder::class)->build('me@example.com');

        $this->assertStringNotContainsString('<script>', $files['html']);
        $this->assertStringNotContainsString('<img src=x', $files['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $files['html']);
        $this->assertSame(0, preg_match('/<(script|link|img|iframe|object|embed)\b|\b(href|src)="|url\(|@import/i', $files['html']), 'inline CSS only: no script, no external resource');
        $this->assertSame('<script>alert(1)</script>', $this->decoded($files)['sections']['orders']['data'][0]['customer_note']);
    }

    public function test_the_json_is_valid_pretty_and_unicode_unescaped_and_matches_the_html(): void
    {
        $this->order('me@example.com', ['customer_note' => 'Моля, позвънете преди доставка']);

        $files = app(PrivacyExportBuilder::class)->build('me@example.com');

        $this->assertStringContainsString("\n    ", $files['json'], 'pretty-printed');
        $this->assertStringContainsString('Моля, позвънете преди доставка', $files['json'], 'unicode unescaped');
        $order = $this->decoded($files)['sections']['orders']['data'][0];
        $this->assertStringContainsString($order['customer_note'], $files['html']);
        $this->assertStringContainsString($order['delivery']['address_line_1'], $files['html']);
        $this->assertStringContainsString($order['lines'][0]['name'], $files['html']);
    }

    public function test_orders_are_matched_by_email_key_and_by_account_and_nobody_elses_are_exported(): void
    {
        $byEmail = $this->order('me@example.com');
        $byAccount = $this->order('other-contact@example.com');
        $someoneElse = $this->order('stranger@example.com');
        DB::table('orders')->where('id', $byAccount)->update(['account_id' => $this->account('me@example.com')]);

        $numbers = array_column($this->decoded(app(PrivacyExportBuilder::class)->build('me@example.com'))['sections']['orders']['data'], 'number');

        $this->assertEqualsCanonicalizing([$byEmail, $byAccount], $numbers);
        $this->assertNotContains($someoneElse, $numbers);

        $this->assertSame([], $this->decoded(app(PrivacyExportBuilder::class)->build('nobody@example.com'))['sections']['orders']['data']);
    }

    public function test_an_anonymised_order_exports_as_it_stands(): void
    {
        $accountId = $this->account('me@example.com');
        $id = $this->order('me@example.com');
        DB::table('orders')->where('id', $id)->update(['account_id' => $accountId, 'status' => 'delivered', 'placed_at' => Carbon::now()->subYears(15)]);
        $this->artisan('privacy:anonymise-orders')->assertSuccessful();
        $this->assertNotNull(DB::table('orders')->where('id', $id)->value('anonymised_at'));

        $files = app(PrivacyExportBuilder::class)->build('me@example.com');
        $order = $this->decoded($files)['sections']['orders']['data'][0];

        $this->assertSame($id, $order['number']);
        $this->assertSame('Анонимизиран', $order['delivery']['recipient_first_name']);
        $this->assertArrayHasKey('anonymised_at', $order);
        $this->assertStringContainsString('Анонимизиран', $files['html']);
    }

    public function test_the_filter_adds_a_section_and_a_throwing_filter_leaves_the_sections_unchanged(): void
    {
        $this->order();

        Hook::filter(PrivacyExportBuilder::FILTER, function (array $sections, string $email): array {
            $sections['lists'] = ['title' => 'Списъци', 'data' => [['name' => 'Newsletter', 'email' => $email]]];
            $sections['broken'] = 'not a section';

            return $sections;
        });

        $data = $this->decoded(app(PrivacyExportBuilder::class)->build('me@example.com'));
        $this->assertSame('Newsletter', $data['sections']['lists']['data'][0]['name']);
        $this->assertSame('me@example.com', $data['sections']['lists']['data'][0]['email']);
        $this->assertArrayNotHasKey('broken', $data['sections'], 'a section of a wrong shape is dropped');

        app(HookRegistry::class)->clear(PrivacyExportBuilder::FILTER);
        Hook::filter(PrivacyExportBuilder::FILTER, static function (): never {
            throw new \RuntimeException('a module is broken');
        });
        Log::spy();

        $after = $this->decoded(app(PrivacyExportBuilder::class)->build('me@example.com'));

        $this->assertSame(['account', 'addresses', 'orders', 'consents', 'lists'], array_keys($after['sections']));
        $this->assertCount(1, $after['sections']['orders']['data']);
        Log::shouldHaveReceived('error')->with('privacy.export_filter_failed', \Mockery::type('array'))->once();
    }

    public function test_a_blank_email_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PrivacyExportBuilder::class)->build('   ');
    }

    public function test_many_orders_are_read_in_chunks_with_a_constant_number_of_queries_per_chunk(): void
    {
        $this->order();
        DB::enableQueryLog();
        app(PrivacyExportBuilder::class)->build('me@example.com');
        $one = count(DB::getQueryLog());

        // 4 more orders for the same person, then the same build: the chunk size (100) is not crossed, so the cost must not grow with the number of orders
        foreach (range(1, 4) as $_) {
            $this->order();
        }
        DB::flushQueryLog();
        $files = app(PrivacyExportBuilder::class)->build('me@example.com');

        $this->assertCount(5, $this->decoded($files)['sections']['orders']['data']);
        $this->assertSame($one, count(DB::getQueryLog()), 'queries per build do not depend on the number of orders (one set per chunk)');
    }
}
