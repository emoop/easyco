<?php

namespace Tests\Feature\Storefront;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Order\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\Feature\Account\Concerns\MakesAccountOrders;
use Tests\TestCase;

/** ACC-3b-1: «Моят профил», the read-only part: dashboard, orders list, one order, the two placeholders. */
class AccountAreaPagesTest extends TestCase
{
    use DrivesAccountApi;
    use MakesAccountOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
    }

    private function me(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->actingAs($account, 'customer');

        return $account;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return [
            'dashboard' => ['/account'],
            'orders' => ['/account/orders'],
            'order' => ['/account/orders/{order}'],
            'addresses' => ['/account/addresses'],
            'details' => ['/account/details'],
        ];
    }

    /** the path with {order} filled by one of HER orders (created on demand) */
    private function resolve(string $path, AccountModel $account): string
    {
        return str_contains($path, '{order}')
            ? str_replace('{order}', $this->makeOrder($account->email, (string) $account->getKey()), $path)
            : $path;
    }

    // ---- access and headers ------------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function test_a_guest_goes_to_the_login_with_this_page_as_the_way_back(string $path): void
    {
        $path = str_replace('{order}', '12', $path);

        $this->get($path)->assertRedirect('/account/login?redirect='.$path);
    }

    #[DataProvider('paths')]
    public function test_a_signed_in_customer_gets_the_page_never_cached_and_noindex(string $path): void
    {
        $account = $this->me();
        $response = $this->get($this->resolve($path, $account))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('xkey'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);
    }

    #[DataProvider('paths')]
    public function test_a_stale_session_is_a_guest_on_every_page(string $path): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $path = str_replace('{order}', $this->makeOrder('me@example.com', (string) $account->getKey()), $path);
        $this->login('me@example.com')->assertOk();
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertOk();

        $this->flushSession();
        $this->withSession([AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');

        $this->get($path)->assertRedirect('/account/login?redirect='.$path);
    }

    #[DataProvider('paths')]
    public function test_the_markup_rules_hold_on_every_page(string $path): void
    {
        $account = $this->me();
        $html = (string) $this->get($this->resolve($path, $account))->assertOk()->getContent();

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);

        foreach ($scripts as [$all, $attributes, $body]) {
            $this->assertSame('', trim($body), 'no inline script body: '.$all);
            $this->assertMatchesRegularExpression('#\bsrc=#i', $attributes);
        }

        $this->assertDoesNotMatchRegularExpression('#<style\b#i', $html);
        $this->assertDoesNotMatchRegularExpression('#\sstyle\s*=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $html);

        preg_match_all('#<(?:script|link|img|iframe|source)\b[^>]*\b(?:src|href)\s*=\s*"([^"]*)"#i', $html, $urls);
        $own = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($urls[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($host === null || $host === $own || $host === 'localhost', "a resource from another host: {$url}");
        }
    }

    #[DataProvider('paths')]
    public function test_the_title_and_breadcrumbs_are_the_shops_own_and_the_menu_marks_the_current_item(string $path): void
    {
        $account = $this->me();
        $html = (string) $this->get($this->resolve($path, $account))->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="sf-breadcrumbs"', $html);
        $this->assertMatchesRegularExpression('#<h1 class="sf-title">#', $html, 'the shop\'s plain title: no account-only class');
        $this->assertStringNotContainsString('sf-account-title', $html);
    }

    public function test_the_menu_has_the_five_items_in_order_with_the_current_one_marked(): void
    {
        $this->me();
        $html = (string) $this->get('/account/orders')->assertOk()->getContent();

        preg_match('#<nav class="sf-acc-menu".*?</nav>#s', $html, $menu);
        preg_match_all('#<(?:a|button)\b[^>]*class="sf-acc-menu-link[^"]*"[^>]*>(.*?)</(?:a|button)>#s', $menu[0], $items);

        $this->assertSame([__('account_pages.menu_dashboard'), __('account_pages.menu_orders'), __('account_pages.menu_addresses'), __('account_pages.menu_details'), __('account_pages.logout')], array_map('trim', $items[1]));
        $this->assertSame(1, substr_count($menu[0], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="/account/orders"\s+aria-current="page"#', $menu[0]);
        $this->assertStringContainsString('href="/account/addresses"', $menu[0]);
        $this->assertStringContainsString('href="/account/details"', $menu[0]);
        $this->assertStringContainsString('data-sf-account-logout-form', $menu[0]);
    }

    // ---- dashboard ---------------------------------------------------------------------------------------------------------------

    public function test_the_dashboard_greets_by_email_and_has_the_four_tiles_and_the_menu(): void
    {
        $this->me('me@example.com');
        $html = (string) $this->get('/account')->assertOk()->getContent();

        $this->assertStringContainsString(e(__('account_pages.home_greeting', ['email' => 'me@example.com'])), $html);
        $this->assertStringContainsString(__('account_pages.home_intro'), $html);

        preg_match('#<ul class="sf-acc-tiles">.*?</ul>#s', $html, $tiles);
        $this->assertSame(4, substr_count($tiles[0], '<li class="sf-acc-tile">'));
        $this->assertSame(4, substr_count($tiles[0], '<svg'), 'one inline icon each');
        $this->assertSame(4, substr_count($tiles[0], 'aria-hidden="true"'));

        foreach (['menu_orders', 'menu_addresses', 'menu_details', 'logout'] as $key) {
            $this->assertStringContainsString('<span class="sf-acc-tile-label">'.__('account_pages.'.$key).'</span>', $tiles[0]);
        }

        $this->assertStringNotContainsString('wishlist', strtolower($tiles[0]));
        $this->assertMatchesRegularExpression('#href="/account"\s+aria-current="page"#', $html, 'the menu marks «Табло»');
        $this->assertSame(2, substr_count($html, 'data-sf-account-logout-form'), 'the menu and the tile each sign out');
    }

    public function test_addresses_and_details_are_a_title_and_coming_soon(): void
    {
        $this->me();

        $this->get('/account/addresses')->assertOk()->assertSee('<h1 class="sf-title">'.__('account_pages.addresses_title').'</h1>', false)->assertSee(__('account_pages.soon'));
        $this->get('/account/details')->assertOk()->assertSee('<h1 class="sf-title">'.__('account_pages.details_title').'</h1>', false)->assertSee(__('account_pages.soon'));
    }

    // ---- orders list -------------------------------------------------------------------------------------------------------------

    public function test_the_list_shows_only_her_orders_newest_first_with_the_item_count_and_the_label_not_the_code(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');

        $old = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-09-01 10:00:00', [['quantity' => 1, 'unit' => 1000]]);
        $new = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-10-05 12:30:00', [['quantity' => 2, 'unit' => 1500], ['quantity' => 3, 'unit' => 100]], null, OrderStatus::SHIPPED);
        $theirs = $this->makeOrder('other@example.com', (string) $other->getKey(), '2026-10-06 12:00:00');
        $guest = $this->makeOrder('me@example.com', null, '2026-10-07 12:00:00');

        $html = (string) $this->get('/account/orders')->assertOk()->getContent();

        preg_match_all('#<th scope="row" class="sf-acc-order-number">№ (\d+)</th>#', $html, $numbers);
        $this->assertSame([$new, $old], $numbers[1], 'newest first, hers only');
        $this->assertStringNotContainsString('№ '.$theirs.'<', $html);
        $this->assertStringNotContainsString('№ '.$guest.'<', $html);

        $this->assertStringContainsString(trans_choice('account_pages.orders_for_items', 5, ['count' => 5]), $html, '2 + 3 units');
        $this->assertStringContainsString(trans_choice('account_pages.orders_for_items', 1, ['count' => 1]), $html);
        $this->assertStringContainsString('<td class="sf-acc-order-status">'.__('orders.status_options.shipped').'</td>', $html);
        $this->assertStringNotContainsString('>shipped<', $html, 'the label, never the internal code');
        $this->assertMatchesRegularExpression('#(05\.10\.2026|2026-10-05)#', $html);
        $this->assertStringContainsString('href="/account/orders/'.$new.'"', $html);

        foreach (['col_order', 'col_date', 'col_status', 'col_total'] as $key) {
            $this->assertStringContainsString('<th scope="col">'.__('account_pages.'.$key).'</th>', $html);
        }

        $this->assertStringNotContainsString('class="sf-acc-pager"', $html, 'one page: no pager');
    }

    public function test_past_one_page_the_older_and_newer_links_appear_and_a_page_past_the_last_is_the_404(): void
    {
        $me = $this->me();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeOrder('me@example.com', (string) $me->getKey(), sprintf('2026-10-%02d 10:00:00', $i));
        }

        $one = (string) $this->get('/account/orders')->assertOk()->getContent();
        $two = (string) $this->get('/account/orders?page=2')->assertOk()->getContent();

        $this->assertSame(20, substr_count($one, 'class="sf-acc-order"'));
        $this->assertSame(5, substr_count($two, 'class="sf-acc-order"'));
        $this->assertStringContainsString('href="/account/orders?page=2" rel="next">'.__('account_pages.pager_older'), $one);
        $this->assertStringNotContainsString(__('account_pages.pager_newer'), $one);
        $this->assertStringContainsString('href="/account/orders" rel="prev">'.__('account_pages.pager_newer'), $two);
        $this->assertStringNotContainsString(__('account_pages.pager_older'), $two);

        $this->get('/account/orders?page=3')->assertNotFound();
    }

    public function test_the_empty_state_says_so_and_links_to_the_shop(): void
    {
        $this->me();

        $this->get('/account/orders')->assertOk()
            ->assertSee(__('account_pages.orders_empty'))
            ->assertSee('href="/"', false)
            ->assertSee(__('account_pages.orders_empty_link'))
            ->assertDontSee('<table', false);
    }

    // ---- order detail ------------------------------------------------------------------------------------------------------------

    public function test_her_order_shows_number_status_lines_totals_delivery_payment_and_tracking(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-10-05 12:30:00', [['quantity' => 2, 'unit' => 1500], ['quantity' => 1, 'unit' => 250]], "first line\n\nsecond line", OrderStatus::SHIPPED);
        DB::table('orders')->where('id', $number)->update(['tracking_number' => 'TRK-123', 'shipping_courier' => 'Speedy']);

        $html = (string) $this->get('/account/orders/'.$number)->assertOk()->getContent();

        $this->assertStringContainsString('<dd>'.$number.'</dd>', $html);
        $this->assertStringContainsString('<dd>'.__('orders.status_options.shipped').'</dd>', $html);
        $this->assertMatchesRegularExpression('#(05\.10\.2026 \d\d:\d\d|2026-10-05 \d\d:\d\d)#', $html);
        $this->assertStringContainsString('Product 0', $html);
        $this->assertStringContainsString('(M)', $html);
        $this->assertStringContainsString(__('storefront.received_quantity').': 2', $html);
        $this->assertStringContainsString(__('storefront.received_subtotal'), $html);
        $this->assertStringContainsString(__('storefront.received_total'), $html);
        $this->assertStringContainsString('Free delivery', $html);
        $this->assertStringContainsString('Vitosha Blvd 1', $html);
        $this->assertStringContainsString('first line', $html);
        $this->assertStringContainsString(__('storefront.received_payment'), $html);
        $this->assertStringContainsString('Speedy / TRK-123', $html);
        $this->assertStringContainsString('href="/account/orders"', $html);
        $this->assertStringContainsString(__('account_pages.back_to_orders'), $html);
        $this->assertStringContainsString('sf-received-lines', $html, 'the order-received page\'s own body markup');
    }

    public function test_an_order_without_a_tracking_number_shows_no_tracking(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey());

        $this->get('/account/orders/'.$number)->assertOk()->assertDontSee(__('account_pages.order_tracking'));
    }

    /** ★ another account's, a guest and an unknown number are the IDENTICAL 404 (the storefront's own) */
    public function test_someone_elses_a_guest_and_an_unknown_order_are_the_same_404(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');
        $mine = $this->makeOrder('me@example.com', (string) $me->getKey());
        $theirs = $this->makeOrder('other@example.com', (string) $other->getKey());
        $guest = $this->makeOrder('me@example.com', null);

        $this->get('/account/orders/'.$mine)->assertOk();

        $bodies = [];

        foreach ([$theirs, $guest, '99999999', 'abc', '0'] as $number) {
            $bodies[] = $this->get('/account/orders/'.$number)->assertNotFound()->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'the same body, nothing about whether the order exists');
    }

    public function test_staff_notes_history_and_the_tracking_of_the_request_are_never_in_the_page(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey());
        DB::table('order_events')->insert(['order_id' => $number, 'type' => 'order_placed', 'to_status' => 'placed', 'reason' => 'STAFF-NOTE-SECRET', 'staff_name' => 'Staffy McStaff', 'occurred_at' => now()]);
        DB::table('order_attributions')->insert(['order_id' => $number, 'source_type' => 'direct', 'capture_status' => 'captured', 'consent_mode' => 'off', 'analytics_consent' => 'granted', 'consent_source' => 'api', 'captured_at' => now(), 'ip' => '203.0.113.77', 'user_agent' => 'UA-SECRET-AGENT', 'landing_page_url' => 'https://example.test/LANDING-SECRET']);
        $client = (int) DB::table('orders')->where('id', $number)->value('client_id');

        foreach (['/account/orders/'.$number, '/account/orders'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();

            foreach (['STAFF-NOTE-SECRET', 'Staffy McStaff', 'UA-SECRET-AGENT', '203.0.113.77', 'LANDING-SECRET', 'SKU-', '777', '+359888123456', 'virtual-'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$secret} on {$path}");
            }

            $this->assertStringNotContainsString('client_id', $html);
        }

        $this->assertGreaterThan(0, $client);
    }

    // ---- the cached pages stay untouched -----------------------------------------------------------------------------------------

    public function test_the_cached_home_page_is_still_identical_for_a_guest_and_a_customer(): void
    {
        $guest = (string) $this->get('/')->assertOk()->getContent();
        $this->me();

        $this->assertSame($guest, (string) $this->get('/')->assertOk()->getContent());
    }

    // ---- query counts ------------------------------------------------------------------------------------------------------------

    private function queries(string $path): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_pins(): void
    {
        $me = $this->me();
        $first = $this->makeOrder('me@example.com', (string) $me->getKey());

        $this->queries('/account');                                  // warm: settings, session, menu
        $dashboard = $this->queries('/account');
        $this->queries('/account/orders');
        $one = $this->queries('/account/orders');
        $detail = $this->queries('/account/orders/'.$first);

        for ($i = 0; $i < 24; $i++) {
            $this->makeOrder('me@example.com', (string) $me->getKey(), sprintf('2026-10-%02d 10:00:00', $i + 1));
        }

        $many = $this->queries('/account/orders');
        $detailMany = $this->queries('/account/orders/'.$first);

        fwrite(STDERR, "\n[query-count] GET /account: {$dashboard}; GET /account/orders: 1 order {$one}, 25 orders {$many}; GET /account/orders/{n}: {$detail} (with 25 orders {$detailMany})\n");

        $this->assertSame($one, $many, 'the list is constant in the number of orders');
        $this->assertSame($detail, $detailMany);
        $this->assertSame(0, $dashboard, 'the dashboard reads nothing but the signed-in account (the menu is cached)');
        $this->assertSame(3, $one);
        $this->assertSame(7, $detail);
    }
}
