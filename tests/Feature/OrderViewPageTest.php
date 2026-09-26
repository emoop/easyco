<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the real, production Order View page (OrderResource's
 * infolist) — admin-panel-design.md §14, Commit 4. Fixture helpers
 * mirror OrderAdminReaderTest/OrderResourceTest's own established
 * shapes.
 */
class OrderViewPageTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function variationId(string $name = 'Product'): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("{$name} {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', string $name = 'Product'): string
    {
        $variationId = $this->variationId($name);

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    private function placeOrder(array $overrides = []): Order
    {
        $variationId = $this->pricedPurchasableVariation($overrides['price'] ?? '10.00', $overrides['productName'] ?? 'Product');
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, $overrides['quantity'] ?? 1, null, null);

        if (isset($overrides['promotionCode'])) {
            $cart->applyPromotionCode($overrides['promotionCode']);
            app(CartRepository::class)->save($cart);
        }

        $get = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: $get('email', 'guest@example.com'),
            recipientName: $get('recipientName', 'Guest Buyer'),
            phone: $get('phone', '+359888000000'),
            paymentMethod: $get('paymentMethod', 'cash_on_delivery'),
            deliveryType: $get('deliveryType', AddressDeliveryType::STREET_ADDRESS),
            country: $get('country', 'BG'),
            city: $get('city', 'Sofia'),
            postalCode: $get('postalCode', null),
            addressLine1: $get('addressLine1', 'Vitosha Blvd 1'),
            carrierCode: $get('carrierCode', null),
            pickupPointReference: $get('pickupPointReference', null),
            settlement: $get('settlement', null),
        );

        return app(CheckoutOrchestrator::class)->place($input, $overrides['placedAt'] ?? new DateTimeImmutable('2026-09-20 10:00:00'))->order();
    }

    public function test_administrator_and_manager_can_view_an_order_product_entry_cannot(): void
    {
        $order = $this->placeOrder();

        $this->actingAsStaffRole('Administrator');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        $this->actingAsStaffRole('Manager');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        $this->actingAsStaffRole('Product Entry');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertForbidden();
    }

    public function test_a_full_order_renders_promotion_payment_and_street_address(): void
    {
        app(PromotionRepository::class)->save(Promotion::create(code: 'save20', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000));
        $this->actingAsStaffRole('Administrator');

        $order = $this->placeOrder([
            'price' => '20.00',
            'quantity' => 2,
            'promotionCode' => 'save20',
            'productName' => 'Full Order Product',
            'city' => 'Plovdiv',
        ]);

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('Guest Buyer', $html);
        $this->assertStringContainsString('save20', $html);
        $this->assertStringContainsString('Full Order Product', $html);
        $this->assertStringContainsString('SKU-', $html);
        $this->assertStringContainsString('Plovdiv', $html);
    }

    public function test_a_minimal_pickup_point_order_with_no_promotion_renders_without_error(): void
    {
        $this->actingAsStaffRole('Administrator');

        $order = $this->placeOrder([
            'deliveryType' => AddressDeliveryType::PICKUP_POINT,
            'country' => null,
            'city' => null,
            'addressLine1' => null,
            'carrierCode' => 'econt',
            'pickupPointReference' => 'EC-123',
            'settlement' => 'Office 1',
        ]);

        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))
            ->assertOk()
            ->assertSee('EC-123');
    }

    /** §3's own mandatory snapshot test, at the rendered View page level (see OrderAdminReaderTest for the same proof at the reader level). */
    public function test_the_view_page_still_shows_the_original_snapshot_after_the_product_changes(): void
    {
        $variationId = $this->pricedPurchasableVariation('25.00', 'Original Product');
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        $order = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-20 10:00:00'))->order();

        // Captured BEFORE the rename/re-sku/re-price below — this is the
        // snapshot value the View page must still show afterward.
        $originalSku = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('sku');

        $productId = (string) VariationModel::find($variationId)->product_id;
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);
        $product->rename('Renamed After Order');
        $product->variations()[0]->setSku('SKU-RENAMED-AFTER');
        app(ProductRepository::class)->save($product);

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal('999.00', 'EUR'), 0),
        ));

        $this->actingAsStaffRole('Administrator');
        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('Original Product', $html);
        $this->assertStringNotContainsString('Renamed After Order', $html);
        $this->assertStringNotContainsString('SKU-RENAMED-AFTER', $html);
        $this->assertStringNotContainsString('999.00', $html);

        // Positive: the ORIGINAL sku and the formatted ORIGINAL unit
        // price / line total (25.00, qty 1, unaffected by the 999.00
        // re-price above) are actually rendered, not merely "the new
        // values are absent" (which a blank/broken render would also
        // satisfy).
        $this->assertStringContainsString($originalSku, $html);
        $this->assertStringContainsString('25.00 €', $html);
    }

    public function test_a_line_with_null_product_name_and_sku_renders_as_a_dash(): void
    {
        $order = $this->placeOrder(['productName' => 'Null Snapshot Product']);

        $originalSku = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('sku');

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->update(['product_name' => null, 'sku' => null]);

        $this->actingAsStaffRole('Administrator');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Null Snapshot Product', $html);
        $this->assertStringNotContainsString($originalSku, $html);

        // Positive, SCOPED TO THE LINE ROW ITSELF — the page has other
        // fields that also fall back to '—' (e.g. account_id), so a plain
        // "the page contains a dash somewhere" check would pass even if
        // the line row itself silently rendered blank. Slicing the html
        // from the Items section heading to the end of its table body
        // (</tbody>) isolates exactly the rendered rows lineRows() built
        // (see OrderResource::lineRows() — a null productName/sku maps to
        // orders.not_available directly). The boundary used to be the NEXT
        // section heading — (en) "Promotion" — but that string is now also
        // a prefix of the Lines table's own "Promotion discount" column
        // header (§3.13 stage 5), which would cut the slice off before the
        // rows; </tbody> is both locale-safe and a tighter scope.
        $linesSectionStart = strpos($html, __('orders.sections.lines'));
        $linesSectionEnd = strpos($html, '</tbody>', $linesSectionStart);
        $this->assertNotFalse($linesSectionStart, 'Items section heading not found in the rendered page');
        $this->assertNotFalse($linesSectionEnd, 'the Items table body was not found in the rendered page');

        $lineRowHtml = substr($html, $linesSectionStart, $linesSectionEnd - $linesSectionStart);

        $this->assertStringContainsString(__('orders.not_available'), $lineRowHtml);
    }

    /**
     * A real, confirmed gap found while adding this helper (see
     * OrderResource::optionLabel()'s own docblock): before, an unknown
     * stored value rendered as the literal translation KEY string
     * ("orders.payment_method_options.xyz"), not the raw value — because
     * Laravel's __() returns the key itself when no translation entry
     * exists (confirmed against installed source, not assumed).
     */
    public function test_an_unknown_payment_method_renders_its_raw_value_not_the_translation_key(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        DB::table('payments')
            ->where('order_id', $order->id())
            ->update(['method' => 'crypto_wallet_xyz']);

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('crypto_wallet_xyz', $html);
        $this->assertStringNotContainsString('orders.payment_method_options.', $html);
    }

    public function test_profit_never_appears_in_the_rendered_view_html(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('profit', strtolower($html));
    }
}
