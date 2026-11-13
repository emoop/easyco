<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\ParcelWeight;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * MEAS-1 section B: the weight snapshot at the sale. A sale line stores the variation's weight at that moment (NULL = unknown),
 * the order and its placement snapshot store the parcel weight = the sum only when EVERY line has one (the same all-or-nothing
 * rule as the shipping quote), a later change of the variation never changes what was stored, and a missing weight never
 * refuses a checkout.
 */
class ParcelWeightSnapshotTest extends TestCase
{
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    /** A priced, stocked SIMPLE product; $grams null = no weight. Returns its variation id. */
    private function product(?int $grams): string
    {
        self::$counter++;
        $product = Product::createSimple('Tee '.self::$counter, 'SKU-W'.self::$counter, 'tee-w'.self::$counter);
        app(ProductRepository::class)->save($product);
        $variationId = (string) $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        if ($grams !== null) {
            DB::table('catalog_variations')->where('id', $variationId)->update(['weight_grams' => $grams]);
        }

        return $variationId;
    }

    /** @param array<string, int> $lines variation id => quantity */
    private function place(array $lines): Order
    {
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));

        foreach ($lines as $variationId => $quantity) {
            app(CartLineAdder::class)->addLine($cart, (string) $variationId, $quantity, null, null);
        }

        app(CartRepository::class)->save($cart);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            email: 'guest@example.com',
            recipientFirstName: 'Guest', recipientLastName: 'Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            accountId: null,
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            addressId: null,
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shippingMethodId: $this->shippingMethodId(), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        );

        return app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-11-13 12:00:00'))->order();
    }

    /** @return array<string, ?int> variation id => the line's stored weight */
    private function lineWeights(Order $order): array
    {
        $out = [];
        foreach (app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId())->saleLines() as $line) {
            $out[$line->priceableId()] = $line->unitWeightGrams();
        }

        return $out;
    }

    private function parcelOf(Order $order): array
    {
        return [
            'order' => DB::table('orders')->where('id', $order->id())->value('parcel_weight_grams'),
            'snapshot' => DB::table('order_placement_snapshots')->where('order_id', $order->id())->value('parcel_weight_grams'),
        ];
    }

    public function test_every_line_weighed_stores_the_unit_weights_and_the_sum(): void
    {
        $a = $this->product(300);
        $b = $this->product(150);

        $order = $this->place([$a => 2, $b => 1]);

        $this->assertSame([$a => 300, $b => 150], $this->lineWeights($order));
        $this->assertSame(750, $order->parcelWeightGrams());
        $this->assertEquals(['order' => 750, 'snapshot' => 750], $this->parcelOf($order));
        $this->assertSame(750, app(\EasyCo\Order\Contracts\OrderRepository::class)->findById($order->id())->parcelWeightGrams());
    }

    public function test_one_unweighed_line_makes_the_parcel_unknown_but_keeps_the_known_line_weight(): void
    {
        $known = $this->product(300);
        $unknown = $this->product(null);

        $order = $this->place([$known => 1, $unknown => 3]);

        $this->assertSame([$known => 300, $unknown => null], $this->lineWeights($order));
        $this->assertNull($order->parcelWeightGrams(), 'unknown, never a partial sum and never 0');
        $this->assertEquals(['order' => null, 'snapshot' => null], $this->parcelOf($order));
    }

    public function test_no_weights_at_all_checkout_is_not_refused_and_everything_stays_null(): void
    {
        $only = $this->product(null);

        $order = $this->place([$only => 2]);

        $this->assertSame([$only => null], $this->lineWeights($order));
        $this->assertEquals(['order' => null, 'snapshot' => null], $this->parcelOf($order));
    }

    public function test_a_later_change_of_the_variation_weight_never_changes_what_was_stored(): void
    {
        $a = $this->product(300);
        $order = $this->place([$a => 2]);

        DB::table('catalog_variations')->where('id', $a)->update(['weight_grams' => 999]);
        DB::table('catalog_variations')->where('id', $a)->update(['weight_grams' => null]);

        $this->assertSame([$a => 300], $this->lineWeights($order));
        $this->assertEquals(['order' => 600, 'snapshot' => 600], $this->parcelOf($order));
        $this->assertSame(600, OrderModel::find($order->id())->parcel_weight_grams);
    }

    public function test_existing_orders_stay_null_the_columns_are_nullable_without_a_default(): void
    {
        foreach (['orders', 'order_placement_snapshots', 'operational_sales_sale_lines'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', $table === 'operational_sales_sale_lines' ? 'unit_weight_grams' : 'parcel_weight_grams');
            $this->assertNotNull($column, $table);
            $this->assertTrue($column['nullable'], $table);
            $this->assertNull($column['default'], $table);
        }
    }

    public function test_the_shared_rule_is_all_or_nothing(): void
    {
        $this->assertSame(0, ParcelWeight::sum([]));
        $this->assertSame(750, ParcelWeight::sum([[300, 2], [150, 1]]));
        $this->assertNull(ParcelWeight::sum([[300, 2], [null, 1]]));
        $this->assertNull(ParcelWeight::sum([[null, 1]]));
    }

    public function test_the_admin_order_label_in_kilograms(): void
    {
        app()->setLocale('bg');
        $this->assertSame('1,35 кг', OrderResource::parcelWeightLabel(1350));
        $this->assertSame('0,35 кг', OrderResource::parcelWeightLabel(350));
        $this->assertSame('2 кг', OrderResource::parcelWeightLabel(2000));
        $this->assertSame('—', OrderResource::parcelWeightLabel(null));

        app()->setLocale('en');
        $this->assertSame('1.35 kg', OrderResource::parcelWeightLabel(1350));
    }
}
