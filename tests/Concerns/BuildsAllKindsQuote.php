<?php

namespace Tests\Concerns;

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
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the stage 5g-2b tests (the `all` quote and the kind-aware hint): a cart of a given goods value, zones (optionally
 * narrowed by postcode) and FLAT methods with a delivery label, a scope, a threshold and a free set — everything through the real
 * repositories and the real HTTP endpoints, like the storefront.
 */
trait BuildsAllKindsQuote
{
    private static int $akCounter = 0;

    private ?PriceList $akPriceList = null;

    private ?string $akZoneId = null;

    /** Puts `$quantity` of a product priced `$price` into the session's cart; returns the cart id. */
    private function akCart(string $price = '30.00', int $quantity = 1): string
    {
        self::$akCounter++;
        $n = self::$akCounter;
        $product = Product::createSimple('All kinds product '.$n, 'AK-'.$n, 'all-kinds-product-'.$n);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->akPriceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->akPriceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 500));

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201)->json('cart_id');
    }

    /** @param list<string>|null $postcodes a zone narrowed to these postcodes only */
    private function akZone(string $name = 'Zone', int $sort = 0, ?array $postcodes = null): string
    {
        $zone = ShippingZone::create($name.' '.(++self::$akCounter), $sort, ['BG'], null, $postcodes);
        app(ShippingZoneRepository::class)->save($zone);

        return (string) $zone->id();
    }

    /** @param list<Type>|null $free */
    private function akMethod(string $name, Scope $scope, ?Type $label, int $amount, ?int $freeAbove = null, ?array $free = null, ?string $courier = null, int $sort = 0, ?string $zoneId = null, ShippingMethodKind $kind = ShippingMethodKind::FLAT, ?string $carrier = null): string
    {
        $zoneId ??= $this->akZoneId ??= $this->akZone();

        $method = ShippingMethod::create(
            $zoneId, $name, $kind, $sort, true, $kind === ShippingMethodKind::CARRIER ? null : $amount, [], $freeAbove, $carrier, false, ShippingClassMode::REPLACE, $courier, $label, $scope, $free,
        );
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** @param array<string, mixed> $extra */
    private function allQuote(array $extra = [], bool $withSettlement = true): TestResponse
    {
        return $this->postJson('/api/shipping/quote', array_merge(['delivery_type' => 'all', 'country' => 'BG'], $withSettlement ? ['settlement' => 'Sofia'] : [], $extra));
    }

    /**
     * An `all` response reduced to what a golden comparison needs: method name => kind => [available, price minor, has a handle,
     * free_applies, free_above_minor, remaining_to_free_minor] (handles are random, so only their presence is compared).
     *
     * @param array<string, mixed> $json
     * @return array<string, array<string, array{0: bool, 1: ?int, 2: bool, 3: bool, 4: ?int, 5: ?int}>>
     */
    private function destinationsByName(array $json): array
    {
        $out = [];

        foreach ($json['methods'] as $method) {
            foreach ($method['destinations'] as $kind => $d) {
                $out[$method['name']][$kind] = [$d['available'], $d['price']['minor'] ?? null, $d['handle'] !== null, $d['free_applies'], $d['free_above_minor'], $d['remaining_to_free_minor']];
            }
        }

        return $out;
    }
}
