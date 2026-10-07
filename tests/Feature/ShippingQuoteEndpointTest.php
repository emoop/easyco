<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingQuoteFilterException;
use App\Services\MethodQuote;
use App\Services\QuoteDestination;
use App\Services\QuoteHandleStore;
use App\Services\ShippingQuoteService;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
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
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 3d part 2 (shipping-domain-design.md §6.8): POST /api/shipping/quote,
 * the `shipping.quotes` merchant filter, the rate limiter and the handle's service code —
 * all through the real HTTP stack, with real carts built the way the storefront builds them.
 */
class ShippingQuoteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10: the guest cart token needs a recognized Referer to engage the session.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function variation(string $price): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Endpoint Product {$n}", "EP-{$n}", "endpoint-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    private function zone(?array $settlements = null): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG'], $settlements);
        app(ShippingZoneRepository::class)->save($zone);

        return $this->zoneId = (string) $zone->id();
    }

    private function method(ShippingMethodKind $kind, string $name, int $sort = 0, ?int $amount = null, ?string $carrier = null, ?int $freeAbove = null): string
    {
        $this->zoneId ??= $this->zone();
        $method = ShippingMethod::create($this->zoneId, $name, $kind, $sort, true, $amount, [], $freeAbove, $carrier, false);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** The guest storefront way: put a line in the cart through the API, which starts the session cart. */
    private function guestCartWith(string $variationId, int $quantity = 2): string
    {
        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201)->json('cart_id');
    }

    private function account(string $email = 'buyer@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return AccountModel::findOrFail($account->id());
    }

    private function body(array $override = []): array
    {
        return array_merge(['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'], $override);
    }

    private function storeLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);
    }

    // --- who is quoted ------------------------------------------------------------------------------------------------------

    public function test_a_guest_is_quoted_with_prices_and_handles(): void
    {
        $a = $this->variation('10.00');
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::FREE, 'Free', 1);
        $cartId = $this->guestCartWith($a);

        $response = $this->postJson('/api/shipping/quote', $this->body())->assertOk();

        $response->assertJsonPath('cart_id', $cartId)
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('goods_after_discount.minor', 2000)
            ->assertJsonPath('zone.id', $this->zoneId)
            ->assertJsonPath('methods.0.id', $flat)
            ->assertJsonPath('methods.0.name', 'Flat')
            ->assertJsonPath('methods.0.kind', 'flat')
            ->assertJsonPath('methods.0.available', true)
            ->assertJsonPath('methods.0.price.minor', 500)
            ->assertJsonPath('methods.0.unavailable_reason', null)
            ->assertJsonPath('methods.1.price.minor', 0);
        $this->assertStringStartsWith('qh_', $response->json('methods.0.handle'));
    }

    public function test_a_signed_in_customer_is_quoted_from_their_own_cart_and_may_use_a_saved_address(): void
    {
        $a = $this->variation('10.00');
        $this->zone(['Sofia']);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $customer = $this->account();
        $this->actingAs($customer, 'customer');
        $this->guestCartWith($a, 3);
        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ivan I', '+359888123456', (string) $customer->id, 'BG', 'Sofia', addressLine1: 'Vitosha 1');
        app(AddressRepository::class)->save($address);

        $typed = $this->postJson('/api/shipping/quote', $this->body())->assertOk();
        $saved = $this->postJson('/api/shipping/quote', ['address_id' => (string) $address->id()])->assertOk();

        $this->assertSame(3000, $typed->json('goods_after_discount.minor'));
        $this->assertSame(500, $saved->json('methods.0.price.minor'));
    }

    public function test_a_client_supplied_cart_token_or_id_is_never_read(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        // Someone else's cart exists...
        $other = Cart::forGuest('someone-elses-token', new \DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($other, $a, 1, null, null);
        $otherId = (string) app(CartRepository::class)->findBySessionToken('someone-elses-token')->id();

        // ...and this visitor has none: naming it changes nothing.
        $this->postJson('/api/shipping/quote', $this->body(['cart_id' => $otherId, 'cart_token' => 'someone-elses-token']))
            ->assertStatus(422)->assertJsonPath('reason', 'empty_cart');
    }

    // --- refusals -----------------------------------------------------------------------------------------------------------

    public function test_an_empty_cart_is_refused_422(): void
    {
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        $this->postJson('/api/shipping/quote', $this->body())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'empty_cart')
            ->assertJsonStructure(['message', 'reason']);
    }

    public function test_a_destination_no_zone_covers_is_refused_422(): void
    {
        $a = $this->variation('10.00');
        $this->zone(['Sofia']);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', $this->body(['settlement' => 'Varna']))
            ->assertStatus(422)->assertJsonPath('reason', 'no_zone_for_destination');
    }

    public function test_a_saved_address_with_no_country_is_refused_422(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $customer = $this->account();
        $this->actingAs($customer, 'customer');
        $this->guestCartWith($a);
        $address = Address::create(AddressDeliveryType::PICKUP_POINT, 'Maria P', '+359888654321', (string) $customer->id, 'BG', carrierCode: 'econt', pickupPointReference: 'o-1', settlement: 'Varna');
        app(AddressRepository::class)->save($address);
        DB::table('addresses')->where('id', $address->id())->update(['country' => null]);

        $this->postJson('/api/shipping/quote', ['address_id' => (string) $address->id()])
            ->assertStatus(422)->assertJsonPath('reason', 'address_incomplete');
    }

    public function test_an_unknown_or_foreign_address_is_404_with_the_same_answer(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $customer = $this->account('mine@example.com');
        $other = $this->account('other@example.com');
        $this->actingAs($customer, 'customer');
        $this->guestCartWith($a);
        $theirs = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ivan I', '+359888123456', (string) $other->id, 'BG', 'Sofia', addressLine1: 'Vitosha 1');
        app(AddressRepository::class)->save($theirs);

        $foreign = $this->postJson('/api/shipping/quote', ['address_id' => (string) $theirs->id()]);
        $unknown = $this->postJson('/api/shipping/quote', ['address_id' => '999999']);

        $foreign->assertStatus(404)->assertJsonPath('reason', 'address_not_found');
        $this->assertSame($foreign->json(), $unknown->json(), 'a foreign address and an unknown one are indistinguishable');
    }

    public function test_a_saved_address_needs_a_customer_session(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', ['address_id' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors(['address_id']);
    }

    public function test_validation_a_bad_country_a_missing_settlement_and_a_missing_delivery_type_are_422(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', $this->body(['country' => 'XX']))->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->postJson('/api/shipping/quote', $this->body(['country' => 'BGR']))->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->postJson('/api/shipping/quote', $this->body(['settlement' => '   ']))->assertStatus(422)->assertJsonValidationErrors(['settlement']);
        $this->postJson('/api/shipping/quote', ['country' => 'BG', 'settlement' => 'Sofia'])->assertStatus(422)->assertJsonValidationErrors(['delivery_type']);
        $this->postJson('/api/shipping/quote', $this->body(['delivery_type' => 'teleport']))->assertStatus(422)->assertJsonValidationErrors(['delivery_type']);
        $this->postJson('/api/shipping/quote', $this->body(['address_id' => '1']))->assertStatus(422)->assertJsonValidationErrors(['address_id']);
    }

    public function test_the_country_is_trimmed_and_uppercased_and_the_settlement_trimmed(): void
    {
        $a = $this->variation('10.00');
        $this->zone(['Sofia']);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', $this->body(['country' => ' bg ', 'settlement' => '  Sofia  ']))
            ->assertOk()->assertJsonPath('methods.0.price.minor', 500);
    }

    public function test_a_pickup_point_is_quoted_from_the_settlement_the_client_sends(): void
    {
        $a = $this->variation('10.00');
        $this->zone(['Sofia']);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();
        // The point chosen afterwards lies in another settlement: the client re-quotes, and that is another zone's answer.
        $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Varna'])
            ->assertStatus(422)->assertJsonPath('reason', 'no_zone_for_destination');
    }

    // --- store locale -------------------------------------------------------------------------------------------------------

    public function test_messages_are_in_the_store_locale_not_the_apps(): void
    {
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        $english = $this->postJson('/api/shipping/quote', $this->body())->assertStatus(422);
        $this->assertSame(__('shipping_quote.refusals.empty_cart', [], 'en'), $english->json('message'));

        $this->storeLocale('bg');

        $bulgarian = $this->postJson('/api/shipping/quote', $this->body())->assertStatus(422);
        $this->assertSame('Количката ви е празна, няма какво да се доставя.', $bulgarian->json('message'));
        $this->assertNotSame($english->json('message'), $bulgarian->json('message'));

        $validation = $this->postJson('/api/shipping/quote', $this->body(['country' => 'XX']))->assertStatus(422);
        $this->assertStringContainsString('Държавата на доставка', $validation->json('errors.country.0'));

        $this->assertSame('en', app()->getLocale(), 'the locale is restored after the request');
    }

    // --- rate limiting ------------------------------------------------------------------------------------------------------

    public function test_the_rate_limit_answers_429_with_a_reason_after_its_budget_in_the_store_locale(): void
    {
        config(['ratelimits.shipping-quote' => 3]);
        $this->storeLocale('bg');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/shipping/quote', $this->body())->assertStatus(422); // counted, whatever the outcome
        }

        $limited = $this->postJson('/api/shipping/quote', $this->body())->assertStatus(429);

        $this->assertSame('too_many_requests', $limited->json('reason'));
        $this->assertSame('Твърде много заявки за цена на доставка. Изчакайте малко и опитайте отново.', $limited->json('message'));
        $this->assertNotNull($limited->headers->get('Retry-After'));
    }

    public function test_the_limiter_is_one_named_limiter_defined_in_one_place(): void
    {
        $this->assertNotNull(\Illuminate\Support\Facades\RateLimiter::limiter('shipping-quote'));
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(fn ($r) => $r->uri() === 'api/shipping/quote');
        $this->assertContains('throttle:shipping-quote', $route->gatherMiddleware());

        $definitions = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), 'RateLimiter::for(')) {
                $definitions[] = $file->getFilename();
            }
        }
        $this->assertSame(['ApiRateLimits.php'], $definitions, 'RateLimiter::for() appears in exactly one place');
    }

    public function test_the_limit_is_per_cart_so_another_visitor_behind_the_same_ip_is_not_throttled(): void
    {
        config(['ratelimits.shipping-quote' => 2]);
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', $this->body())->assertOk();
        $this->postJson('/api/shipping/quote', $this->body())->assertOk();
        $this->postJson('/api/shipping/quote', $this->body())->assertStatus(429);

        // A different session (another cart identity) from the same IP has its own budget.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession([])->postJson('/api/shipping/quote', $this->body())->assertStatus(422);
    }

    // --- the shipping.quotes filter ----------------------------------------------------------------------------------------

    public function test_with_no_listener_the_list_passes_through_untouched(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);

        $this->postJson('/api/shipping/quote', $this->body())->assertOk()->assertJsonCount(1, 'methods')->assertJsonPath('methods.0.price.minor', 500);
    }

    public function test_a_filter_can_remove_a_method_and_change_an_amount_and_the_handle_binds_the_changed_amount(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $free = $this->method(ShippingMethodKind::FREE, 'Free', 1);
        $other = $this->method(ShippingMethodKind::FLAT, 'Other', 2, 900);
        $this->guestCartWith($a);

        Hook::filter('shipping.quotes', function (array $methods, array $context): array {
            $this->assertSame('BG', $context['country']);
            $this->assertArrayNotHasKey('email', $context);

            $kept = [];
            foreach ($methods as $m) {
                if ($m->name === 'Free') {
                    continue; // removed
                }
                $kept[] = $m->name === 'Flat' ? MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, 250) : $m;
            }

            return $kept;
        });

        $response = $this->postJson('/api/shipping/quote', $this->body())->assertOk();

        $this->assertSame(['Flat', 'Other'], array_column($response->json('methods'), 'name'), 'Free was removed');
        $this->assertNotContains($free, array_column($response->json('methods'), 'id'));
        $this->assertSame(250, $response->json('methods.0.price.minor'));
        $this->assertSame(900, $response->json('methods.1.price.minor'));

        $entry = Cache::get(QuoteHandleStore::KEY_PREFIX.hash('sha256', $response->json('methods.0.handle')));
        $this->assertSame(250, $entry['amount'], 'the handle binds the FINAL amount, not the one the rule computed');
        $this->assertSame($other, $response->json('methods.1.id'));
    }

    public function test_a_filter_may_reorder_but_the_original_order_is_kept(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'First', 0, 500);
        $this->method(ShippingMethodKind::FLAT, 'Second', 1, 600);
        $this->guestCartWith($a);
        Hook::filter('shipping.quotes', fn (array $methods): array => array_reverse($methods));

        $this->postJson('/api/shipping/quote', $this->body())->assertOk();
        $this->assertSame(['First', 'Second'], array_column($this->postJson('/api/shipping/quote', $this->body())->json('methods'), 'name'));
    }

    public function test_a_filter_adding_a_method_that_is_not_in_the_zone_is_refused_by_name(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a);
        Hook::filter('shipping.quotes', function (array $methods): array {
            $methods[] = MethodQuote::priced('99999', 'Sneaky', 'flat', false, 'EUR', 1);

            return $methods;
        });

        Log::spy();
        $this->postJson('/api/shipping/quote', $this->body())
            ->assertStatus(500)->assertJsonPath('reason', 'quote_filter_invalid');
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c = []) => ($c['reason'] ?? null) === 'unknown_method')->once();
    }

    /** @return array<string, array{callable(MethodQuote): mixed, string}> */
    public static function badFilterOutputs(): array
    {
        return [
            'not a list' => [fn () => 'nothing', ShippingQuoteFilterException::NOT_A_LIST],
            'an item that is not a method' => [fn (MethodQuote $m) => [$m, 'x'], ShippingQuoteFilterException::INVALID_ITEM],
            'a method twice' => [fn (MethodQuote $m) => [$m, $m], ShippingQuoteFilterException::DUPLICATE_METHOD],
            'an amount below zero' => [fn (MethodQuote $m) => [MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, -1)], ShippingQuoteFilterException::NEGATIVE_AMOUNT],
            'a renamed method' => [fn (MethodQuote $m) => [MethodQuote::priced($m->methodId, 'Renamed', $m->kind, $m->requiresPickupPoint, $m->currency, 500)], ShippingQuoteFilterException::CHANGED_FIELD],
            'another currency' => [fn (MethodQuote $m) => [MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, 'USD', 500)], ShippingQuoteFilterException::CHANGED_FIELD],
            'another kind' => [fn (MethodQuote $m) => [MethodQuote::priced($m->methodId, $m->name, 'free', $m->requiresPickupPoint, $m->currency, 500)], ShippingQuoteFilterException::CHANGED_FIELD],
            'a method made unavailable' => [fn (MethodQuote $m) => [MethodQuote::unavailable($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, 'provider_error')], ShippingQuoteFilterException::AVAILABILITY_CHANGED],
            'a handle smuggled in' => [fn (MethodQuote $m) => [$m->withHandle('qh_forged')], ShippingQuoteFilterException::CHANGED_FIELD],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFilterOutputs')]
    public function test_every_other_thing_a_filter_returns_is_refused_by_name(callable $filter, string $reason): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cartFor($a);
        Hook::filter('shipping.quotes', fn (array $methods) => $filter($methods[0]));

        try {
            app(ShippingQuoteService::class)->quote($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));
            $this->fail('the filter output must be refused');
        } catch (ShippingQuoteFilterException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_a_filter_cannot_make_an_unavailable_method_priced(): void
    {
        $a = $this->variation('10.00');
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => throw new \RuntimeException('down')));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $cart = $this->cartFor($a);
        Hook::filter('shipping.quotes', fn (array $methods): array => [MethodQuote::priced($methods[0]->methodId, $methods[0]->name, $methods[0]->kind, $methods[0]->requiresPickupPoint, $methods[0]->currency, 1)]);

        $this->expectException(ShippingQuoteFilterException::class);
        app(ShippingQuoteService::class)->quote($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));
    }

    private function cartFor(string $variationId): Cart
    {
        $cart = Cart::forGuest('endpoint-'.(++self::$counter), new \DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, 2, null, null);

        return app(CartRepository::class)->findById($cart->id());
    }

    // --- the service code in the handle (part 1 review) -------------------------------------------------------------------

    public function test_a_carrier_handle_binds_the_quoted_service_and_a_local_one_binds_none(): void
    {
        $a = $this->variation('10.00');
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => [Fakes::quote(600), new \EasyCo\Shipping\Carrier\ShippingQuote('address', 'To address', 450, 'EUR')]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $carrierId = $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $flatId = $this->method(ShippingMethodKind::FLAT, 'Flat', 1, 500);
        $cart = $this->cartFor($a);

        $result = app(ShippingQuoteService::class)->quote($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));
        $store = app(QuoteHandleStore::class);
        $carrier = $result->method($carrierId);
        $flat = $result->method($flatId);
        $cartId = (string) $cart->id();

        $this->assertSame('address', $carrier->serviceCode);
        $this->assertTrue($store->verify($carrier->handle, $cartId, $carrierId, 450, 'EUR', $result->pricingHash, 'address'));
        $this->assertFalse($store->verify($carrier->handle, $cartId, $carrierId, 450, 'EUR', $result->pricingHash, 'office'), 'another service than the one quoted');
        $this->assertFalse($store->verify($carrier->handle, $cartId, $carrierId, 450, 'EUR', $result->pricingHash, null), 'no service named');
        $this->assertTrue($store->verify($flat->handle, $cartId, $flatId, 500, 'EUR', $result->pricingHash, null));
        $this->assertFalse($store->verify($flat->handle, $cartId, $flatId, 500, 'EUR', $result->pricingHash, 'address'), 'a local method has no service to book');
    }

    // --- the free-shipping threshold facts (stage 3e, §5.1) --------------------------------------------------------

    public function test_each_method_reports_its_free_above_and_remaining(): void
    {
        $a = $this->variation('10.00');
        $threshold = $this->method(ShippingMethodKind::FLAT, 'Econt office', 0, 500, freeAbove: 10000);
        $plain = $this->method(ShippingMethodKind::FLAT, 'To address', 1, 500);
        $this->guestCartWith($a, 1); // 10.00 goods

        $response = $this->postJson('/api/shipping/quote', $this->body())->assertOk();

        $response->assertJsonPath('methods.0.id', $threshold)
            ->assertJsonPath('methods.0.free_above_minor', 10000)
            ->assertJsonPath('methods.0.remaining_to_free_minor', 9000)
            ->assertJsonPath('methods.1.id', $plain)
            ->assertJsonPath('methods.1.free_above_minor', null)
            ->assertJsonPath('methods.1.remaining_to_free_minor', null);
    }

    public function test_the_top_level_hint_reports_the_smallest_remaining(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Econt office', 0, 500, freeAbove: 10000);
        $this->method(ShippingMethodKind::FLAT, 'To address', 1, 500, freeAbove: 5000);
        $this->guestCartWith($a, 2); // 20.00 goods: 5000 - 2000 = 3000 is smaller than 8000

        $response = $this->postJson('/api/shipping/quote', $this->body())->assertOk();

        $response->assertJsonPath('free_shipping_hint.state', 'remaining')
            ->assertJsonPath('free_shipping_hint.method_name', 'To address')
            ->assertJsonPath('free_shipping_hint.free_above_minor', 5000)
            ->assertJsonPath('free_shipping_hint.remaining_minor', 3000);
    }

    public function test_the_top_level_hint_is_null_when_no_method_has_a_threshold(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->guestCartWith($a, 1);

        $this->postJson('/api/shipping/quote', $this->body())->assertOk()->assertJsonPath('free_shipping_hint', null);
    }

    public function test_the_handle_is_issued_for_a_threshold_method_and_binds_its_amount_only(): void
    {
        $a = $this->variation('10.00');
        $flat = $this->method(ShippingMethodKind::FLAT, 'Econt office', 0, 500, freeAbove: 10000);
        $cart = $this->cartFor($a); // quantity 2 -> 20.00 goods

        $result = app(ShippingQuoteService::class)->quote($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));
        $method = $result->method($flat);

        $this->assertSame(10000, $method->freeAboveMinor);
        $this->assertSame(8000, $method->remainingToFreeMinor);
        $this->assertTrue(
            app(QuoteHandleStore::class)->verify($method->handle, (string) $cart->id(), $flat, 500, 'EUR', $result->pricingHash, null),
            'the handle binds the amount; the threshold facts never enter it',
        );
    }

    public function test_a_filter_may_still_change_the_amount_of_a_threshold_method(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Econt office', 0, 500, freeAbove: 10000);
        $this->guestCartWith($a, 1);

        // A filter that rebuilds the quote via MethodQuote::priced() carries no threshold facts,
        // and that must NOT be refused: they are information, not part of the method's identity.
        Hook::filter('shipping.quotes', fn (array $methods): array => [
            MethodQuote::priced($methods[0]->methodId, $methods[0]->name, $methods[0]->kind, $methods[0]->requiresPickupPoint, $methods[0]->currency, 250),
        ]);

        $this->postJson('/api/shipping/quote', $this->body())->assertOk()->assertJsonPath('methods.0.price.minor', 250);
    }

    public function test_the_quote_without_a_hint_reads_no_extra_settings_row(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Plain', 0, 500); // no threshold -> no hint
        $this->guestCartWith($a, 1);

        $counts = $this->measureQuoteCold();

        fwrite(STDERR, sprintf(
            "\n[query-count] POST /api/shipping/quote (cold, no hint): %d shipping + %d settings reads (%d total)\n",
            $counts['shipping'], $counts['settings'], $counts['total'],
        ));

        $this->assertSame(3, $counts['shipping'], 'the zones, the methods and their rates');
        $this->assertSame(1, $counts['settings'], 'the store locale the controller already reads');
    }

    public function test_the_quote_with_a_hint_reuses_those_reads_and_adds_one_settings_row(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Econt office', 0, 500, freeAbove: 10000);
        $this->guestCartWith($a, 1);

        $counts = $this->measureQuoteCold();

        fwrite(STDERR, sprintf(
            "\n[query-count] POST /api/shipping/quote (cold, with a hint): %d shipping + %d settings reads (%d total)\n",
            $counts['shipping'], $counts['settings'], $counts['total'],
        ));

        $this->assertSame(3, $counts['shipping'], 'the hint reuses the quote pipeline, it does not re-read zones/methods/rates');
        $this->assertSame(2, $counts['settings'], 'the store locale plus the currency symbol position the hint formats with');
    }

    /**
     * A COLD measured POST /api/shipping/quote (a fresh set of scoped instances). One cold
     * measurement per test: the container's forgetScopedInstances() reliably resets the memo once.
     *
     * @return array{shipping: int, settings: int, total: int}
     */
    private function measureQuoteCold(): array
    {
        $this->app->forgetScopedInstances();

        $counts = ['shipping' => 0, 'settings' => 0, 'total' => 0];
        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;
            if (str_contains($query->sql, 'shipping_')) {
                $counts['shipping']++;
            } elseif (str_contains($query->sql, 'site_settings')) {
                $counts['settings']++;
            }
        });

        $this->postJson('/api/shipping/quote', $this->body())->assertOk();

        DB::flushQueryLog();

        return $counts;
    }
}
