<?php

namespace Tests\Concerns;

use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
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
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the checkout RN-1 tests (the region field and the customer's note): a real order through POST /api/checkout with the
 * cheapest honest shipping (ProvidesCheckoutShipping: a FREE home method and a FREE pickup method, a well-formed handle and the
 * exact amount), and helpers to set the merchant's per-country region rule the way the settings page stores it.
 */
trait PlacesRegionOrders
{
    use ProvidesCheckoutShipping;

    private static int $regionCounter = 0;

    private ?PriceList $regionPriceList = null;

    protected function setUpRegionOrders(): void
    {
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function regionCart(): string
    {
        self::$regionCounter++;
        $n = self::$regionCounter;
        $product = Product::createSimple('Region product '.$n, 'RG-'.$n, 'region-product-'.$n);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->regionPriceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->regionPriceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 500));

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, mixed> a typed street address in $country */
    private function streetFields(string $country = 'BG', array $extra = []): array
    {
        return array_merge(['delivery_type' => 'street_address', 'country' => $country, 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1'], $extra);
    }

    /** @return array<string, mixed> a typed pickup point */
    private function pickupFields(array $extra = []): array
    {
        return array_merge([
            'delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1',
            'pickup_point_name' => 'Econt Center', 'pickup_point_address' => 'Vitosha Blvd 100', 'settlement' => 'Sofia',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function regionPayload(string $cartId, array $address, array $extra = []): array
    {
        return array_merge(
            ['cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_name' => 'Guest Buyer', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery'],
            $this->shippingPayload(($address['delivery_type'] ?? '') === 'pickup_point'),
            $address,
            $extra,
        );
    }

    /** Posts a checkout for a fresh cart. */
    private function checkout(array $address, array $extra = []): TestResponse
    {
        return $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), $address, $extra));
    }

    /** Stores the merchant's rule the way the settings page does. @param array<string, string> $countries ISO => mode */
    private function regionRule(string $default = 'optional', array $countries = []): void
    {
        $settings = app(SiteSettingsRepository::class);
        $default === 'optional' ? $settings->forget(CheckoutRegionPolicy::DEFAULT_KEY) : $settings->set(CheckoutRegionPolicy::DEFAULT_KEY, $default);
        $countries === [] ? $settings->forget(CheckoutRegionPolicy::COUNTRIES_KEY) : $settings->set(CheckoutRegionPolicy::COUNTRIES_KEY, json_encode($countries, JSON_FORCE_OBJECT));
    }
}
