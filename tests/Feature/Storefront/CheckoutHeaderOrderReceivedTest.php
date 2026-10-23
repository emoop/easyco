<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
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
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/** DS-2b: the order-received page (the signed page a real order leads to) shows the simplified header too, and the full one while the feature is off. */
class CheckoutHeaderOrderReceivedTest extends TestCase
{
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withHeader('Referer', 'http://localhost/');
        config(['app.name' => 'Raf Shop']);
    }

    private function placeOrder(): string
    {
        $product = Product::createSimple('Received product', 'RCV-1', 'received-product');
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($list);
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $list->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 50));

        $cartId = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201)->json('cart_id');

        return (string) $this->postJson('/api/checkout', [
            'cart_id' => (string) $cartId, 'email' => 'guest@example.com', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Petrov', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery', 'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ])->assertStatus(201)->json('confirmation_url');
    }

    public function test_the_order_received_page_shows_the_simplified_header_when_it_is_published(): void
    {
        $url = $this->placeOrder();
        $this->assertStringContainsString('data-sf-header', $this->get($url)->assertOk()->getContent(), 'off: the full header');

        app(CheckoutHeaderCompiler::class)->publish(array_replace(CheckoutHeaderCatalog::defaults(), ['enabled' => true, 'phone' => '+359 888 123 456']), app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-sf-checkout-header', $html);
        $this->assertStringNotContainsString('data-sf-header', $html);
        $this->assertStringContainsString('href="tel:+359888123456"', $html);
    }
}
