<?php

namespace Tests\Feature;

use App\Http\TrustedProxyList;
use App\Services\CheckoutInput;
use App\Services\CheckoutRequestFacts;
use App\Services\OrderContextWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\OrderContextSettings;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 7.4, 9.6, 11.3 and the architect decision of section 4): what checkout writes about the
 * request that placed an order. The storefront is cookie-free, so the origin row is the honest "not captured yet" row plus the request facts
 * that need no storage; the client address is recorded only as the CLIENT's (proxies are configured, never assumed) and never leaks; the rows
 * are written inside the placement transaction, so a double submit meets no unique key and a rolled-back placement leaves nothing.
 */
class OrderContextCheckoutTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    protected function tearDown(): void
    {
        // The trusted proxies are process-wide state of Symfony's Request: leave none behind for the next test.
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    private function set(string $key, string $value): void
    {
        app(SiteSettingsRepository::class)->set($key, $value);
    }

    /** Places one order from $ip with the given user agent; returns the order id. */
    private function place(string $ip = '203.0.113.5', string $agent = self::IPHONE): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $agent);

        return (string) $this->checkout($this->streetFields('BG'))->assertStatus(201)->json('order.id');
    }

    private function attribution(string $orderId): object
    {
        $rows = DB::table('order_attributions')->where('order_id', $orderId)->get();
        $this->assertCount(1, $rows, 'exactly one attribution row per order');

        return $rows[0];
    }

    // ---- the row, and the decided values ------------------------------------------------------------------------------------------

    public function test_every_placed_order_has_exactly_one_attribution_row_with_the_decided_values(): void
    {
        $orderId = $this->place();
        $row = $this->attribution($orderId);

        // The architect decision (order-context-design.md section 4 amendment): the storefront is cookie-free, nothing about the visit is known.
        $this->assertSame('unknown', $row->source_type);
        $this->assertSame('skipped_no_data', $row->capture_status);
        foreach ([
            'last_utm_source', 'last_utm_medium', 'last_utm_campaign', 'last_utm_content', 'last_utm_term', 'last_utm_id', 'referrer_host', 'referrer_url',
            'click_id_type', 'click_id_value', 'ai_platform', 'landing_page_url', 'session_pages', 'session_count', 'last_touch_at',
            'first_source_type', 'first_utm_source', 'first_utm_medium', 'first_utm_campaign', 'first_landing_page_url', 'first_touch_at', 'visitor_id',
        ] as $column) {
            $this->assertNull($row->{$column}, "{$column} stays NULL until the consent-aware capture exists");
        }

        // The consent snapshot, and the request facts that need no storage.
        $this->assertSame('strict', $row->consent_mode, 'privacy.consent_mode defaults to strict');
        $this->assertSame('unknown', $row->analytics_consent);
        $this->assertSame('not_asked', $row->consent_source);
        $this->assertSame('203.0.113.5', $row->ip);
        $this->assertNull($row->ip_anonymised_at);
        $this->assertSame(self::IPHONE, $row->user_agent);
        $this->assertSame('mobile', $row->device_type);
        $this->assertNull($row->account_id, 'a guest');

        // captured_at IS the placement instant.
        $this->assertSame(DB::table('orders')->where('id', $orderId)->value('placed_at'), $row->captured_at);
    }

    public function test_the_consent_mode_snapshot_follows_the_setting_and_an_unreadable_value_is_strict(): void
    {
        $this->set('privacy.consent_mode', 'notice');
        $this->assertSame('notice', $this->attribution($this->place())->consent_mode);

        $this->set('privacy.consent_mode', 'garbage');
        $this->assertSame('strict', $this->attribution($this->place())->consent_mode, 'never silently looser');
    }

    public function test_the_user_agent_is_truncated_to_the_configured_length_and_never_beyond_the_column(): void
    {
        $long = str_repeat('a', 400);

        $this->assertSame(255, mb_strlen($this->attribution($this->place('203.0.113.5', $long))->user_agent), 'default 255');

        $this->set(OrderContextSettings::USER_AGENT_MAX_LENGTH_KEY, '40');
        $this->assertSame(40, mb_strlen($this->attribution($this->place('203.0.113.5', $long))->user_agent));

        $this->set(OrderContextSettings::USER_AGENT_MAX_LENGTH_KEY, '9999');
        $this->assertSame(255, mb_strlen($this->attribution($this->place('203.0.113.5', $long))->user_agent), 'a value past the column is the column');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function agents(): array
    {
        return [
            'iPhone' => [self::IPHONE, 'mobile'],
            'Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36', 'mobile'],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'tablet'],
            'Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', 'tablet'],
            'desktop Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', 'desktop'],
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'bot'],
            'curl' => ['curl/8.4.0', 'bot'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agents')]
    public function test_the_device_class_is_derived_from_the_user_agent(string $agent, string $expected): void
    {
        $this->assertSame($expected, $this->attribution($this->place('203.0.113.5', $agent))->device_type);
    }

    public function test_a_retention_of_zero_never_keeps_the_full_address(): void
    {
        $this->set(OrderContextSettings::IP_RETENTION_DAYS_KEY, '0');

        $row = $this->attribution($this->place('203.0.113.5'));

        $this->assertSame('203.0.113.0', $row->ip, 'stored already anonymised');
        $this->assertNotNull($row->ip_anonymised_at, 'and said so');
    }

    public function test_something_that_is_not_an_address_is_not_stored_as_one(): void
    {
        $order = $this->place('203.0.113.5');
        DB::table('order_attributions')->where('order_id', $order)->delete();
        DB::table('order_events')->where('order_id', $order)->delete();

        app(OrderContextWriter::class)->write($order, $this->input(new CheckoutRequestFacts('not-an-ip', "bad\xB1agent")), new DateTimeImmutable('2026-10-18 10:00:00'));

        $row = $this->attribution($order);
        $this->assertNull($row->ip);
        $this->assertSame('bad?agent', $row->user_agent, 'invalid UTF-8 is scrubbed, never a database error');
    }

    public function test_a_caller_with_no_request_still_gets_its_row_and_a_logged_in_account_is_snapshotted(): void
    {
        $order = $this->place();
        DB::table('order_attributions')->where('order_id', $order)->delete();
        DB::table('order_events')->where('order_id', $order)->delete();

        app(OrderContextWriter::class)->write($order, $this->input(null, accountId: '42'), new DateTimeImmutable('2026-10-18 10:00:00'));

        $row = $this->attribution($order);
        $this->assertNull($row->ip);
        $this->assertNull($row->user_agent);
        $this->assertSame('unknown', $row->device_type);
        $this->assertSame('unknown', $row->source_type);
        $this->assertSame(42, (int) $row->account_id, 'the fact "logged in as account X" outlives the account');
    }

    // ---- the client address behind a proxy (section 7.4) --------------------------------------------------------------------------

    public function test_a_forged_x_forwarded_for_is_not_recorded_when_no_proxy_is_configured(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'), 'the default trusts no proxy');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->withHeader('X-Forwarded-For', '198.51.100.99');
        $order = (string) $this->checkout($this->streetFields('BG'))->assertStatus(201)->json('order.id');

        $this->assertSame('203.0.113.5', $this->attribution($order)->ip, 'the direct peer, never the forged header');
        $this->assertNull(DB::table('order_attributions')->where('ip', '198.51.100.99')->first());
    }

    public function test_the_real_client_is_recorded_through_a_configured_proxy(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.9']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->withHeader('X-Forwarded-For', '198.51.100.20');
        $order = (string) $this->checkout($this->streetFields('BG'))->assertStatus(201)->json('order.id');

        $this->assertSame('198.51.100.20', $this->attribution($order)->ip, 'the client behind the proxy, not the proxy');
    }

    public function test_a_forwarded_header_from_a_peer_that_is_not_the_configured_proxy_is_still_ignored(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.9']]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->withHeader('X-Forwarded-For', '198.51.100.20');
        $order = (string) $this->checkout($this->streetFields('BG'))->assertStatus(201)->json('order.id');

        $this->assertSame('203.0.113.5', $this->attribution($order)->ip, 'configuring a proxy does not make every caller one');
    }

    public function test_the_trusted_proxy_list_takes_addresses_and_ranges_and_never_a_wildcard(): void
    {
        $this->assertSame([], TrustedProxyList::parse(null));
        $this->assertSame([], TrustedProxyList::parse(''));
        $this->assertSame(['10.0.0.5', '10.0.1.0/24', '2001:db8::/32'], TrustedProxyList::parse(' 10.0.0.5, 10.0.1.0/24 ,2001:db8::/32,10.0.0.5'));
        $this->assertSame(['10.0.0.5'], TrustedProxyList::parse('*,**,10.0.0.5,0.0.0.0/0,::/0,10.0.0.0/33,proxy.example.com,not-an-ip'), 'a wildcard, a /0, a hostname and a typo are dropped, never guessed at');
    }

    // ---- the address never leaks (section 7.2) ------------------------------------------------------------------------------------

    public function test_the_full_address_is_never_in_a_log_line_an_activity_row_an_exception_or_a_response(): void
    {
        $ip = '203.0.113.77';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->level.' '.$event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $bodies = [];

        // 1. a successful placement
        $bodies[] = $this->checkout($this->streetFields('BG'))->assertStatus(201)->getContent();

        // 2. a refusal (an unknown payment method)
        $bodies[] = $this->checkout($this->streetFields('BG'), ['payment_method' => 'nope'])->assertStatus(422)->getContent();

        // 3. a validation failure
        $bodies[] = $this->checkout($this->streetFields('BG'), ['email' => 'not-an-email'])->assertStatus(422)->getContent();

        // 4. a placement that FAILS in the middle (the origin insert throws): the logged failure and the 500 body
        \App\Models\OrderAttributionModel::creating(static function (): void {
            throw new \RuntimeException('the writer failed');
        });
        $bodies[] = $this->checkout($this->streetFields('BG'))->assertStatus(500)->getContent();
        \App\Models\OrderAttributionModel::flushEventListeners();

        // 5. the retention command, with the address aged out
        DB::table('order_attributions')->update(['captured_at' => now()->subDays(60)]);
        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $everything = implode("\n", $logged)."\n".implode("\n", $bodies)."\n".json_encode(DB::table('activity_log')->get()->all());

        $this->assertStringNotContainsString($ip, $everything);
        $this->assertNotEmpty($logged, 'the failure and the command did log (the check is not vacuous)');
        $this->assertStringNotContainsString('203.0.113.0', $everything, 'not even its anonymised form is logged: counts only');
    }

    // ---- double submit (section 9.6) ----------------------------------------------------------------------------------------------

    public function test_a_double_submit_is_one_order_with_one_attribution_row_one_invoice_row_and_no_duplicate_key(): void
    {
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), [
            'invoice' => ['recipient_type' => 'company', 'legal_name' => 'Acme OOD', 'tax_id' => '123456789', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => '1 Main St'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->withHeader('User-Agent', self::IPHONE);
        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        // A different address and device on the second press: the order keeps the context it was placed with.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeader('User-Agent', 'curl/8.4.0');
        $second = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $first->assertJsonPath('already_placed', false);
        $second->assertJsonPath('already_placed', true);
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_attributions')->count());
        $this->assertSame(1, DB::table('order_invoice_details')->count());
        $this->assertSame(1, DB::table('order_events')->where('type', 'placed')->count());

        $row = DB::table('order_attributions')->first();
        $this->assertSame('203.0.113.5', $row->ip, 'the pre-existing order keeps the context it was placed with');
        $this->assertSame('mobile', $row->device_type);
    }

    public function test_a_lost_cart_claim_rolls_the_context_back_with_the_order(): void
    {
        // A lost claim: a concurrent request took the cart between the fast path and this attempt's own claim (zero rows affected).
        $this->app->extend(\EasyCo\Cart\Contracts\CartRepository::class, fn (\EasyCo\Cart\Contracts\CartRepository $inner) => new class($inner) implements \EasyCo\Cart\Contracts\CartRepository {
            public function __construct(private readonly \EasyCo\Cart\Contracts\CartRepository $inner)
            {
            }

            public function save(\EasyCo\Cart\Cart $cart): void { $this->inner->save($cart); }

            public function findById(string $id): ?\EasyCo\Cart\Cart { return $this->inner->findById($id); }

            public function findByAccountId(string $accountId): ?\EasyCo\Cart\Cart { return $this->inner->findByAccountId($accountId); }

            public function findBySessionToken(string $sessionToken): ?\EasyCo\Cart\Cart { return $this->inner->findBySessionToken($sessionToken); }

            public function delete(string $cartId): void { $this->inner->delete($cartId); }

            public function deleteExpired(\DateTimeImmutable $now): int { return $this->inner->deleteExpired($now); }

            public function claimForOrder(string $cartId, string $orderId): bool { return false; }

            public function findOrderIdForCart(string $cartId): ?string { return $this->inner->findOrderIdForCart($cartId); }

            public function findClaimForIdentity(string $cartId, ?string $accountId, ?string $sessionToken): ?\EasyCo\Cart\CartClaim { return $this->inner->findClaimForIdentity($cartId, $accountId, $sessionToken); }
        });

        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), [
            'invoice' => ['recipient_type' => 'individual', 'legal_name' => 'Ivan Ivanov', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => '1 Main St'],
        ]);

        $this->postJson('/api/checkout', $payload);

        $this->assertSame(0, DB::table('orders')->count(), 'the claim was lost: nothing was placed');
        $this->assertSame(0, DB::table('order_attributions')->count());
        $this->assertSame(0, DB::table('order_invoice_details')->count());
        $this->assertSame(0, DB::table('order_events')->count());
    }

    // ---- the placed event (section 11.3) ------------------------------------------------------------------------------------------

    public function test_every_order_has_exactly_one_placed_event_at_the_placement_instant_and_it_is_the_oldest_row(): void
    {
        $order = $this->place();

        $events = DB::table('order_events')->where('order_id', $order)->orderBy('id')->get();
        $this->assertCount(1, $events);
        $placed = $events[0];

        $this->assertSame('placed', $placed->type);
        $this->assertSame(DB::table('orders')->where('id', $order)->value('placed_at'), $placed->occurred_at, 'occurred_at IS placed_at');
        $this->assertNull($placed->from_status);
        $this->assertNull($placed->to_status);
        $this->assertNull($placed->reason);
        $this->assertNull($placed->staff_id, 'checkout is nobody in the panel');

        // A later transition's row comes after it, and carries from_status = placed's own status.
        app(\App\Services\OrderStatusChanger::class)->confirm($order, new DateTimeImmutable('+1 day'), 'ok');
        $timeline = DB::table('order_events')->where('order_id', $order)->orderBy('occurred_at')->orderBy('id')->pluck('type')->all();
        $this->assertSame(['placed', 'status_changed'], $timeline);
    }

    public function test_the_placed_event_has_a_label_in_both_languages(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $label = __('orders.event_type_options.placed', [], $locale);

            $this->assertNotSame('orders.event_type_options.placed', $label);
            $this->assertNotSame('', trim($label));
        }
    }

    // ---- the cost ------------------------------------------------------------------------------------------------------------------

    public function test_the_context_costs_exactly_its_inserts_and_no_settings_query(): void
    {
        $order = $this->place(); // the checkout request has already read (and remembered) every setting
        DB::table('order_attributions')->where('order_id', $order)->delete();
        DB::table('order_events')->where('order_id', $order)->delete();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(OrderContextWriter::class)->write($order, $this->input(new CheckoutRequestFacts('203.0.113.5', 'agent')), new DateTimeImmutable('2026-10-18 10:00:00'));

        $this->assertCount(2, $queries, 'the attribution insert and the placed-event insert');
        $this->assertStringStartsWith('insert into `order_attributions`', $queries[0]);
        $this->assertStringStartsWith('insert into `order_events`', $queries[1]);

        // With an invoice asked for: one more insert, still nothing else.
        DB::table('order_attributions')->where('order_id', $order)->delete();
        DB::table('order_events')->where('order_id', $order)->delete();
        $queries = [];
        $invoice = new \App\Services\CheckoutInvoiceRequest(\App\Enums\InvoiceRecipientType::INDIVIDUAL, 'Ivan Ivanov', 'BG', 'Sofia', '1000', '1 Main St');

        app(OrderContextWriter::class)->write($order, new CheckoutInput('1', 'guest@example.com', 'Guest', 'Buyer', '+359888000000', 'cash_on_delivery', requestFacts: new CheckoutRequestFacts('203.0.113.5', 'agent'), invoice: $invoice), new DateTimeImmutable('2026-10-18 10:00:00'));

        $this->assertCount(3, $queries);
        $this->assertStringStartsWith('insert into `order_invoice_details`', $queries[1]);
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------------------------

    private function input(?CheckoutRequestFacts $facts, ?string $accountId = null): CheckoutInput
    {
        return new CheckoutInput(
            cartId: '1', email: 'guest@example.com', recipientFirstName: 'Guest', recipientLastName: 'Buyer', phone: '+359888000000',
            paymentMethod: 'cash_on_delivery', accountId: $accountId, requestFacts: $facts,
        );
    }
}
