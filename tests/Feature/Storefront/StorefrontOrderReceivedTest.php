<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Orders\OrderConfirmationUrl;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/** C1: the confirmation_url of POST /api/checkout and the signed order-received page it leads to. */
class StorefrontOrderReceivedTest extends TestCase
{
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withHeader('Referer', 'http://localhost/');
        config(['app.name' => 'Raf Shop']);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------

    private function variation(string $price = '10.00', int $stock = 50): string
    {
        self::$counter++;
        $n = (string) self::$counter;

        $product = Product::createSimple("Received product {$n}", "RCV-{$n}", "received-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function payload(string $cartId, string $method = 'cash_on_delivery', array $extra = []): array
    {
        return [
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_first_name' => 'Ivan', 'recipient_last_name' => '<b>Petrov</b>',
            'phone' => '+359888000000',
            'payment_method' => $method,
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'postal_code' => '1000',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
            ...$extra,
        ];
    }

    /** Adds $lines lines to the cart and places the order; returns the checkout response. */
    private function placeOrder(int $lines = 1, string $method = 'cash_on_delivery'): TestResponse
    {
        $cartId = null;

        for ($i = 0; $i < $lines; $i++) {
            $cartId = $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 2])->assertStatus(201)->json('cart_id');
        }

        return $this->postJson('/api/checkout', $this->payload((string) $cartId, $method))->assertStatus(201);
    }

    private function notFoundBody(): string
    {
        return $this->get('/product/this-does-not-exist')->assertNotFound()->getContent();
    }

    // ---- the confirmation_url --------------------------------------------------------------------------------

    public function test_checkout_returns_a_signed_30_day_url_and_it_renders_the_order(): void
    {
        $response = $this->placeOrder(2);
        $url = $response->json('confirmation_url');
        $orderId = (string) $response->json('order.id');

        $this->assertIsString($url);
        $this->assertStringContainsString('/order/received/'.$orderId.'?', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), (int) $query['expires'], 60, 'expires in 30 days');

        $page = $this->get($url)->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString('<title>Order received — Raf Shop</title>', $html);
        $this->assertStringContainsString($orderId, $html);
        $this->assertSame(2, substr_count($html, 'class="sf-received-line"'));
        $this->assertStringContainsString('Received product', $html);
        $this->assertStringContainsString('20.00 €', $html, 'a line total (2 x 10.00)');
        $this->assertStringContainsString('40.00 €', $html, 'the order total');
        $this->assertStringContainsString('Free delivery', $html);
        $this->assertStringContainsString('Vitosha Blvd 1', $html);
        $this->assertStringContainsString('1000 Sofia', $html);
        $this->assertStringContainsString('Cash on delivery', $html);
    }

    public function test_a_replay_returns_a_working_url_for_the_same_order(): void
    {
        $first = $this->placeOrder();
        $cartId = (string) DB::table('carts')->whereNotNull('order_id')->value('id');

        $replay = $this->postJson('/api/checkout', $this->payload($cartId))->assertStatus(201);

        $replay->assertJsonPath('already_placed', true);
        $this->assertSame($first->json('order.id'), $replay->json('order.id'));
        $this->get($replay->json('confirmation_url'))->assertOk()->assertSee((string) $first->json('order.id'));
    }

    public function test_the_response_gained_exactly_one_field(): void
    {
        $keys = array_keys($this->placeOrder()->json());

        $this->assertSame(['order', 'shipping', 'payment', 'already_placed', 'confirmation_url'], $keys);
    }

    public function test_the_url_service_is_the_one_place_that_builds_it(): void
    {
        $url = app(OrderConfirmationUrl::class)->forOrder('42');

        $this->assertTrue(URL::hasCorrectSignature(\Illuminate\Http\Request::create($url)));
        $this->assertSame(30, OrderConfirmationUrl::DAYS);
    }

    // ---- the page is private ---------------------------------------------------------------------------------

    public function test_the_page_is_private_no_store_no_referrer_noindex_and_cookie_free(): void
    {
        $response = $this->get($this->placeOrder()->json('confirmation_url'))->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $response->getContent());
        $this->assertStringNotContainsString('rel="canonical"', $response->getContent());
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['session']->driver()->isStarted());
    }

    public function test_an_unsigned_or_wrongly_signed_request_is_the_same_404_as_any_missing_page(): void
    {
        $body = $this->notFoundBody();
        $url = $this->placeOrder()->json('confirmation_url');
        $orderId = (string) DB::table('orders')->value('id');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $variants = [
            'no signature' => url('/order/received/'.$orderId),
            'tampered signature' => preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url),
            'tampered expiry' => preg_replace('/expires=\d+/', 'expires='.($q['expires'] + 999999), $url),
            'another order number, same signature' => str_replace('/order/received/'.$orderId.'?', '/order/received/'.($orderId + 1).'?', $url),
            'signed for another route' => URL::temporarySignedRoute('storefront.product', now()->addDay(), ['slug' => $orderId]),
            'a foreign key' => $this->signedWithAnotherKey('/order/received/'.$orderId),
        ];

        foreach ($variants as $label => $variant) {
            $response = $this->get($variant);

            $this->assertSame(404, $response->getStatusCode(), $label);
            $this->assertSame($body, $response->getContent(), "{$label}: the body differs from the ordinary 404");
        }
    }

    private function signedWithAnotherKey(string $path): string
    {
        $expires = now()->addDay()->getTimestamp();
        $url = url($path).'?expires='.$expires;

        return $url.'&signature='.hash_hmac('sha256', $url, 'some-other-application-key');
    }

    public function test_an_expired_url_is_the_same_404(): void
    {
        $body = $this->notFoundBody();
        $url = $this->placeOrder()->json('confirmation_url');

        $this->get($url)->assertOk();

        $this->travel(31)->days();
        $this->assertSame($body, $this->get($url)->assertNotFound()->getContent());
    }

    public function test_a_valid_signature_for_an_unknown_or_malformed_number_is_the_same_404(): void
    {
        $body = $this->notFoundBody();

        foreach (['999999', '0', 'abc', '1.5', '01', str_repeat('9', 30), '-1'] as $number) {
            $url = URL::temporarySignedRoute('storefront.order.received', now()->addDay(), ['orderNumber' => $number]);

            $this->assertSame($body, $this->get($url)->assertNotFound()->getContent(), $number);
        }
    }

    // ---- the payment block ------------------------------------------------------------------------------------

    private function setBankDetails(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set('payment.bank_transfer.account_holder', 'Raf Ltd');
        $settings->set('payment.bank_transfer.bank_name', 'Test Bank');
        $settings->set('payment.bank_transfer.iban', 'BG80BNBG96611020345678');
        $settings->set('payment.bank_transfer.bic', 'BNBGBGSD');
        $settings->set('payment.bank_transfer.deadline_days', '5');
        $settings->set('payment.bank_transfer.instructions', "Please pay promptly.\nThank you.");
    }

    public function test_a_bank_transfer_order_shows_the_bank_details_from_the_store_settings(): void
    {
        $this->setBankDetails();
        $response = $this->placeOrder(1, 'bank_transfer');

        $html = $this->get($response->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('Pay by bank transfer', $html);
        $this->assertStringContainsString('Raf Ltd', $html);
        $this->assertStringContainsString('Test Bank', $html);
        $this->assertStringContainsString('BG80 BNBG 9661 1020 3456 78', $html, 'the IBAN in groups of four, as in the email');
        $this->assertStringContainsString('BNBGBGSD', $html);
        $this->assertStringContainsString('Order '.$response->json('order.id'), $html, 'the reason for payment is the order number');
        $this->assertStringContainsString('within 5 day(s)', $html);
        $this->assertStringContainsString("Please pay promptly.\nThank you.", $html);
        $this->assertStringNotContainsString('Amount to pay on delivery', $html);
    }

    public function test_a_bank_transfer_order_without_bank_settings_shows_no_block_and_still_renders(): void
    {
        $response = $this->placeOrder(1, 'bank_transfer');

        $html = $this->get($response->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-bank-details', $html);
        $this->assertStringContainsString('Bank transfer', $html);
    }

    public function test_a_cash_on_delivery_order_has_the_one_line_note_and_no_bank_block(): void
    {
        $this->setBankDetails();
        $html = $this->get($this->placeOrder(1, 'cash_on_delivery')->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('Amount to pay on delivery: 20.00 €', $html);
        $this->assertStringNotContainsString('sf-bank-details', $html);
        $this->assertStringNotContainsString('Raf Ltd', $html);
    }

    public function test_the_page_is_translated_in_the_store_locale(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $html = $this->get($this->placeOrder()->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('Благодарим ви', $html);
        $this->assertStringContainsString('Наложен платеж', $html);
    }

    public function test_the_page_says_where_the_confirmation_was_sent_and_has_no_save_this_address_text(): void
    {
        $html = $this->get($this->placeOrder()->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="sf-received-email">We sent a confirmation to guest@example.com.</p>', $html);
        $this->assertStringNotContainsString('Keep this page address', $html);
        $this->assertStringNotContainsString('only way to see this order again', $html);
        $this->assertDoesNotMatchRegularExpression('/account|sign in|log in/i', $html, 'the page is cookie-free: it cannot know who is logged in, so it says nothing about an account');
    }

    public function test_the_confirmation_sentence_is_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $html = $this->get($this->placeOrder()->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('Изпратихме потвърждение на guest@example.com.', $html);
        $this->assertStringNotContainsString('Запазете адреса', $html);
    }

    public function test_the_email_in_the_sentence_is_escaped(): void
    {
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->json('cart_id');
        $url = $this->postJson('/api/checkout', $this->payload($cartId, 'cash_on_delivery', ['email' => "o'brien&co@example.com"]))->assertStatus(201)->json('confirmation_url');

        $this->assertStringContainsString('We sent a confirmation to o&#039;brien&amp;co@example.com.', $this->get($url)->assertOk()->getContent());
    }

    public function test_hostile_text_in_the_order_is_escaped(): void
    {
        $html = $this->get($this->placeOrder()->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<b>Petrov</b>', $html);
        $this->assertDoesNotMatchRegularExpression('/<script|onerror=|javascript:/i', $html);
    }

    // ---- C1b: the delivery kind, the region and the customer's note ---------------------------------------------------------

    /** A pick-up order (the free pick-up method), optionally with the kind and a note. */
    private function placePickupOrder(?string $kind, array $extra = []): TestResponse
    {
        $cartId = $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');

        return $this->postJson('/api/checkout', [
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Petrov',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'pickup_point',
            'country' => 'BG',
            'settlement' => 'Sofia',
            'carrier_code' => 'Econt',
            'pickup_point_reference' => 'EC-1',
            'pickup_point_name' => 'Econt Center',
            'pickup_point_address' => '1 Main St',
            ...($kind === null ? [] : ['pickup_point_kind' => $kind]),
            ...$this->shippingPayload(true),
            ...$extra,
        ])->assertStatus(201);
    }

    public function test_a_pickup_order_shows_the_kind_the_customer_chose_next_to_the_point(): void
    {
        $office = $this->get($this->placePickupOrder('office')->json('confirmation_url'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<span class="sf-received-destination-line sf-received-destination-kind">Office<\/span>\s*<span class="sf-received-destination-line">Ivan Petrov<\/span>/', $office);

        $this->flushSession();
        $locker = $this->get($this->placePickupOrder('locker')->json('confirmation_url'))->assertOk()->getContent();
        $this->assertStringContainsString('sf-received-destination-kind">Locker</span>', $locker);
        $this->assertStringNotContainsString('>Office</span>', $locker);
    }

    public function test_a_pickup_order_placed_without_a_kind_and_an_address_order_show_no_kind_line(): void
    {
        $this->flushSession();
        $this->assertStringNotContainsString('sf-received-destination-kind', $this->get($this->placePickupOrder(null)->json('confirmation_url'))->getContent());

        $this->flushSession();
        $this->assertStringNotContainsString('sf-received-destination-kind', $this->get($this->placeOrder()->json('confirmation_url'))->getContent());
    }

    public function test_the_kind_label_is_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $this->assertStringContainsString('sf-received-destination-kind">Автомат</span>', $this->get($this->placePickupOrder('locker')->json('confirmation_url'))->getContent());
    }

    public function test_the_region_of_a_street_order_is_shown_after_the_city(): void
    {
        $cartId = $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $url = $this->postJson('/api/checkout', $this->payload((string) $cartId, 'cash_on_delivery', ['region' => 'Sofia-city']))->assertStatus(201)->json('confirmation_url');

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/1000 Sofia<\/span>\s*<span class="sf-received-destination-line">Sofia-city<\/span>\s*<span class="sf-received-destination-line">Bulgaria<\/span>/', $html);
    }

    public function test_the_note_is_its_own_escaped_block_with_its_line_breaks_kept_by_css(): void
    {
        $note = "First <b>line</b>\n\n  Second <script>alert(1)</script> line  \nThird";
        $html = $this->get($this->placePickupOrder('office', ['customer_note' => $note])->json('confirmation_url'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<p class="sf-received-note">(.*?)<\/p>/s', $html, $block), 'one note block');
        $this->assertSame("Your note: First &lt;b&gt;line&lt;/b&gt;\nSecond &lt;script&gt;alert(1)&lt;/script&gt; line\nThird", $block[1], 'escaped, blank lines dropped, every line break a real newline');
        $this->assertStringNotContainsString('<br', $block[0], 'no markup is injected for the breaks');
        $this->assertDoesNotMatchRegularExpression('/<script|<b>/i', $html);
        $this->assertLessThan(strpos($html, 'sf-received-note'), strpos($html, '</address>'), 'the block comes after the destination, not inside it');

        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
        $this->assertMatchesRegularExpression('/\.sf-received-note \{[^}]*white-space: pre-line;/s', $css, 'the breaks are shown by CSS');
    }

    public function test_without_a_note_there_is_no_note_block(): void
    {
        $this->assertStringNotContainsString('sf-received-note', $this->get($this->placePickupOrder('office')->json('confirmation_url'))->getContent());
    }

    // ---- the cost ---------------------------------------------------------------------------------------------

    public function test_the_query_count_of_the_page_is_the_same_for_one_and_for_five_lines(): void
    {
        $count = function (string $url): int {
            $this->app->forgetScopedInstances();

            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });
            $this->get($url)->assertOk();

            return count($sql);
        };

        $one = $count($this->placeOrder(1)->json('confirmation_url'));

        $this->flushSession();
        $five = $count($this->placeOrder(5)->json('confirmation_url'));

        fwrite(STDERR, "\n[query-count] storefront order-received page: 1 line = {$one} queries, 5 lines = {$five} queries (pinned ceiling 7)\n");

        $this->assertSame($one, $five, 'the page query count must not grow with the number of lines');
        $this->assertLessThanOrEqual(7, $five);
    }
}
