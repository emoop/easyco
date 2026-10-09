<?php

namespace Tests\Feature\Mail;

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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvidesCheckoutShipping;

/**
 * Places a REAL order through POST /api/checkout (cart built through the cart API), the way
 * CheckoutControllerTest does, so the mail tests exercise the real order.placed hook and the real order rows.
 * The class using this trait needs RefreshDatabase and the Referer header (see setUpPlacesOrders()).
 */
trait PlacesOrdersForMail
{
    use ProvidesCheckoutShipping;

    private static int $mailProductCounter = 0;

    private ?PriceList $mailPriceList = null;

    private string $mailCartId = '';

    protected function setUpPlacesOrders(): void
    {
        // Sanctum's stateful pipeline needs a recognised Referer for the guest cart token (account-domain-design.md §10).
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function mailVariation(string $name, string $price, int $stock): array
    {
        self::$mailProductCounter++;
        $n = self::$mailProductCounter;

        $product = Product::createSimple($name, "MAIL-SKU-{$n}", "mail-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->mailPriceList === null) {
            $this->mailPriceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->mailPriceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->mailPriceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return ['productId' => (string) $product->id(), 'variationId' => $variationId, 'sku' => "MAIL-SKU-{$n}"];
    }

    /**
     * Places an order and returns the checkout response body.
     *
     * @param  array<string, mixed>  $overrides  checkout payload overrides
     * @return array{order_id: string, product_id: string, response: array<string, mixed>}
     */
    private function placeMailOrder(array $overrides = [], string $name = 'Blue Shirt', string $price = '10.00', int $quantity = 2): array
    {
        $item = $this->mailVariation($name, $price, 20);

        $this->mailCartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $item['variationId'],
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart_id');

        $response = $this->postJson('/api/checkout', array_merge([
            'cart_id' => $this->mailCartId,
            'email' => 'buyer@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ], $overrides));

        $response->assertStatus(201);

        return ['order_id' => (string) $response->json('order.id'), 'product_id' => $item['productId'], 'response' => $response->json()];
    }

    private function renameMailProduct(string $productId, string $newName): void
    {
        DB::table('catalog_products')->where('id', $productId)->update(['name' => $newName]);
    }
}
