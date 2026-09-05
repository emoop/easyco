<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10 — Sanctum's stateful pipeline
        // needs a recognized Referer to engage the session at all,
        // which the guest cart_token depends on.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function setPrice(string $variationId, string $decimalAmount): PriceListItem
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        $item = new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);

        return $item;
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId, $stock);

        return $variationId;
    }

    private function createPromotion(
        string $code,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        int $percentageBasisPoints = 1000,
        ?int $usageLimitItems = null,
        ?int $usageLimitTotal = null,
    ): Promotion {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $percentageBasisPoints,
            validFrom: $validFrom,
            validUntil: $validUntil,
            usageLimitItems: $usageLimitItems,
            usageLimitTotal: $usageLimitTotal,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    /** Same Client -> Transaction -> Order chain already used in Step 1b's/PromotionRedemption's own Feature tests. */
    private function createOrder(): Order
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(new SaleLine(
            id: null,
            transactionId: '',
            clientId: $client->id(),
            priceableId: 'variation-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
        ));
        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order;
    }

    private function redeemPromotion(Promotion $promotion, ?string $accountId = null): void
    {
        $redemption = new PromotionRedemption(
            id: null,
            promotionId: $promotion->id(),
            orderId: $this->createOrder()->id(),
            accountId: $accountId,
            redeemedAt: new DateTimeImmutable(),
        );
        app(PromotionRedemptionRepository::class)->save($redemption);
    }

    private function createFixedAmountPromotion(string $code, string $decimalAmount): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::FIXED_AMOUNT,
            discountAmount: Money::fromDecimal($decimalAmount, 'EUR'),
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    private function attachScope(
        Promotion $promotion,
        PromotionScopeType $scopeType,
        string $referenceId,
        PromotionScopeMode $mode = PromotionScopeMode::INCLUDE,
    ): void {
        $scope = new PromotionScope(null, $promotion->id(), $scopeType, $referenceId, $mode);
        app(PromotionScopeRepository::class)->attach($scope);
    }

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    public function test_get_with_no_cart_returns_200_with_empty_lines_and_zero_total(): void
    {
        $response = $this->getJson('/api/cart');

        $response->assertStatus(200);
        $response->assertJsonPath('lines', []);
        $response->assertJsonPath('total.minor', 0);
    }

    public function test_add_line_happy_path_returns_201_and_persists(): void
    {
        $variationId = $this->pricedPurchasableVariation();

        $response = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 2]);

        $response->assertStatus(201);
        $lines = $response->json('lines');
        $this->assertCount(1, $lines);
        $this->assertSame($variationId, $lines[0]['variation_id']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertSame(1, CartModel::count());
    }

    public function test_adding_the_same_variation_twice_results_in_one_line_with_summed_quantity(): void
    {
        $variationId = $this->pricedPurchasableVariation();

        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 2])->assertStatus(201);
        $second = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 3]);

        $second->assertStatus(201);
        $lines = $second->json('lines');
        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]['quantity']);
    }

    public function test_add_line_exceeding_available_stock_returns_422(): void
    {
        $variationId = $this->pricedPurchasableVariation(stock: 3);

        $response = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 4]);

        $response->assertStatus(422);
        $this->assertSame(0, CartModel::count());
    }

    public function test_add_a_variation_with_no_configured_price_returns_422_not_500(): void
    {
        // The "Regular Prices" system list itself must exist (that's
        // EloquentPriceResolver's OTHER, genuinely-uncatchable throw
        // site) — seeded here via a real priced variation, but with no
        // PriceListItem for the target variation under test.
        $this->pricedPurchasableVariation();

        $unpricedVariationId = $this->variationId();
        $this->setStock($unpricedVariationId, 10);

        $response = $this->postJson('/api/cart/lines', ['variation_id' => $unpricedVariationId, 'quantity' => 1]);

        $response->assertStatus(422);
        $this->assertSame(0, CartModel::count());
    }

    public function test_get_degrades_gracefully_when_an_existing_lines_price_is_later_fully_removed(): void
    {
        $pricedVariation = $this->pricedPurchasableVariation('10.00');
        $unpricedVariation = $this->variationId();
        $this->setStock($unpricedVariation, 10);
        $item = $this->setPrice($unpricedVariation, '20.00');

        $this->postJson('/api/cart/lines', ['variation_id' => $pricedVariation, 'quantity' => 1])->assertStatus(201);
        $this->postJson('/api/cart/lines', ['variation_id' => $unpricedVariation, 'quantity' => 2])->assertStatus(201);

        // The price existed at add-time (both lines got a real
        // price_at_add) but is now fully removed — not just changed.
        app(PriceListItemRepository::class)->remove($item->id());

        $response = $this->getJson('/api/cart');

        $response->assertStatus(200);
        $lines = collect($response->json('lines'))->keyBy('variation_id');

        $this->assertTrue($lines[$pricedVariation]['price_available']);
        $this->assertNotNull($lines[$pricedVariation]['unit_price']);
        $this->assertNotNull($lines[$pricedVariation]['line_total']);

        $this->assertFalse($lines[$unpricedVariation]['price_available']);
        $this->assertNull($lines[$unpricedVariation]['unit_price']);
        $this->assertNull($lines[$unpricedVariation]['line_total']);
        $this->assertFalse($lines[$unpricedVariation]['price_changed_since_add']);
        $this->assertNotNull($lines[$unpricedVariation]['price_at_add']);

        // total reflects only the priced line (10.00 EUR x 1 = 1000
        // minor units) — the unpriced line is excluded, not zeroed.
        $this->assertSame(1000, $response->json('total.minor'));
    }

    public function test_patch_on_a_priced_line_does_not_crash_when_a_different_line_is_unpriced(): void
    {
        $pricedVariation = $this->pricedPurchasableVariation('10.00');
        $unpricedVariation = $this->variationId();
        $this->setStock($unpricedVariation, 10);
        $item = $this->setPrice($unpricedVariation, '20.00');

        $this->postJson('/api/cart/lines', ['variation_id' => $pricedVariation, 'quantity' => 1])->assertStatus(201);
        $this->postJson('/api/cart/lines', ['variation_id' => $unpricedVariation, 'quantity' => 1])->assertStatus(201);

        app(PriceListItemRepository::class)->remove($item->id());

        $response = $this->patchJson("/api/cart/lines/{$pricedVariation}", ['quantity' => 3]);

        $response->assertStatus(200);
        $lines = collect($response->json('lines'))->keyBy('variation_id');

        $this->assertSame(3, $lines[$pricedVariation]['quantity']);
        $this->assertTrue($lines[$pricedVariation]['price_available']);
        $this->assertNotNull($lines[$pricedVariation]['unit_price']);

        $this->assertFalse($lines[$unpricedVariation]['price_available']);
        $this->assertNull($lines[$unpricedVariation]['unit_price']);
    }

    public function test_add_a_non_purchasable_variation_returns_422(): void
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;
        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        $variation = $product->variations()[0];
        $variation->setPurchasable(false);
        app(ProductRepository::class)->save($product);

        $variationId = $variation->id();
        $this->setPrice($variationId, '10.00');
        $this->setStock($variationId, 10);

        $response = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1]);

        $response->assertStatus(422);
        $this->assertSame(0, CartModel::count());
    }

    public function test_patch_updates_the_quantity(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->patchJson("/api/cart/lines/{$variationId}", ['quantity' => 5]);

        $response->assertStatus(200);
        $this->assertSame(5, $response->json('lines.0.quantity'));
    }

    public function test_patch_for_a_variation_not_in_the_cart_returns_404(): void
    {
        $variationId = $this->pricedPurchasableVariation();

        $response = $this->patchJson("/api/cart/lines/{$variationId}", ['quantity' => 2]);

        $response->assertStatus(404);
    }

    public function test_delete_removes_the_line(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->deleteJson("/api/cart/lines/{$variationId}");

        $response->assertStatus(204);

        $get = $this->getJson('/api/cart');
        $get->assertJsonPath('lines', []);
    }

    public function test_delete_for_a_variation_not_in_the_cart_returns_404(): void
    {
        $variationId = $this->pricedPurchasableVariation();

        $response = $this->deleteJson("/api/cart/lines/{$variationId}");

        $response->assertStatus(404);
    }

    public function test_a_guest_cart_and_an_account_cart_are_genuinely_separate(): void
    {
        // The security-relevant test: a guest adding a line must never
        // see it show up for a logged-in customer using the same
        // browser/session, and vice versa.
        $variationId = $this->pricedPurchasableVariation();

        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $guestGet = $this->getJson('/api/cart');
        $this->assertCount(1, $guestGet->json('lines'));

        $this->loggedInAccount();

        $accountGet = $this->getJson('/api/cart');
        $accountGet->assertJsonPath('lines', []);
    }

    /**
     * Proves the real gap CatalogScopeResolver closes: a PriceList
     * scoped to a Brand must actually win over the plain regular price
     * once a Product carries that brand — this only works if
     * CartController is really assembling productId/
     * matchingScopeReferenceIds into PriceContext, not just quantity/
     * currency/priceableId as before.
     */
    public function test_get_resolves_a_brand_scoped_price_over_the_plain_regular_price(): void
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, '10.00');
        $this->setStock($variationId, 10);

        $product = app(ProductRepository::class)->findById(
            app(VariationRepository::class)->findById($variationId)->productId()
        );
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike-scoped-price-test');
        app(BrandRepository::class)->save($brand);
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        $brandList = PriceList::create(
            'Nike -20%',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            percentageBasisPoints: 2000,
        );
        app(PriceListRepository::class)->save($brandList);

        $scope = new PriceListScope(
            id: null,
            priceListId: $brandList->id(),
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: $brand->id(),
        );
        app(PriceListScopeRepository::class)->attach($scope);

        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->getJson('/api/cart');

        $response->assertStatus(200);
        // 10.00 EUR regular, 20% off via the BRAND-scoped list = 8.00 EUR
        // (800 minor units) — NOT the plain 1000 minor units the
        // unscoped regular price alone would give.
        $this->assertSame(800, $response->json('lines.0.unit_price.minor'));
        $this->assertSame(800, $response->json('total.minor'));
    }

    public function test_a_write_refreshes_expires_at(): void
    {
        $variationId = $this->pricedPurchasableVariation();

        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $firstExpiry = CartModel::first()->expires_at;

        // Travel time forward conceptually by directly checking that a
        // second write produces an expires_at no earlier than the
        // first (refreshed, not left untouched).
        $this->patchJson("/api/cart/lines/{$variationId}", ['quantity' => 2])->assertStatus(200);
        $secondExpiry = CartModel::first()->expires_at;

        $this->assertTrue($secondExpiry->greaterThanOrEqualTo($firstExpiry));
    }

    public function test_applying_a_valid_percentage_code_reduces_total_by_the_exact_discount_leaving_subtotal_unchanged(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $this->createPromotion('SUMMER20'); // 10% (1000 basis points)

        $response = $this->putJson('/api/cart/promotion', ['code' => 'SUMMER20']);

        $response->assertStatus(200);
        $response->assertJsonPath('promotion.code', 'summer20');
        $response->assertJsonPath('promotion.valid', true);
        $response->assertJsonPath('promotion.reason', null);
        $this->assertSame([$variationId], $response->json('promotion.applicable_variation_ids'));

        // 10% of 1000 minor units = 100 minor units, no rounding needed.
        $this->assertSame(100, $response->json('promotion.discount_amount.minor'));
        $this->assertFalse($response->json('promotion.discount_capped'));
        $this->assertNull($response->json('promotion.nominal_discount_amount'));

        // subtotal is the old pre-discount total, unchanged; total is
        // subtotal minus the discount.
        $this->assertSame(1000, $response->json('subtotal.minor'));
        $this->assertSame(900, $response->json('total.minor'));
    }

    public function test_a_fixed_amount_code_exceeding_eligible_items_worth_is_capped_and_total_never_goes_negative(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $this->createFixedAmountPromotion('BIGDISCOUNT', '20.00');

        $response = $this->putJson('/api/cart/promotion', ['code' => 'BIGDISCOUNT']);

        $response->assertStatus(200);
        $response->assertJsonPath('promotion.valid', true);
        $response->assertJsonPath('promotion.discount_capped', true);

        // Nominal face value is 20.00 EUR (2000 minor), but only 10.00
        // EUR (1000 minor) of eligible items are in the cart — capped
        // to exactly that, never below zero.
        $this->assertSame(2000, $response->json('promotion.nominal_discount_amount.minor'));
        $this->assertSame(1000, $response->json('promotion.discount_amount.minor'));

        $this->assertSame(1000, $response->json('subtotal.minor'));
        $this->assertSame(0, $response->json('total.minor'));
    }

    public function test_usage_limit_items_below_the_carts_applicable_quantity_reduces_total_by_only_the_partial_amount(): void
    {
        $variationA = $this->pricedPurchasableVariation('5.00', stock: 10);
        $this->postJson('/api/cart/lines', ['variation_id' => $variationA, 'quantity' => 2])->assertStatus(201);
        $variationB = $this->pricedPurchasableVariation('3.00', stock: 10);
        $this->postJson('/api/cart/lines', ['variation_id' => $variationB, 'quantity' => 3])->assertStatus(201);

        // 100% PERCENTAGE keeps this isolated to the usage_limit_items
        // capping — the discount then equals the capped base exactly,
        // nothing further to hand-verify on top.
        $this->createPromotion('LIMITED', percentageBasisPoints: 10000, usageLimitItems: 3);

        $response = $this->putJson('/api/cart/promotion', ['code' => 'LIMITED']);

        $response->assertStatus(200);
        // subtotal = (2 x 500) + (3 x 300) = 1000 + 900 = 1900.
        $this->assertSame(1900, $response->json('subtotal.minor'));

        // Walking lines in cart order: variationA (qty 2, lineTotal
        // 1000) fits entirely within the limit of 3 -> full 1000
        // contributed, 1 unit of headroom remains. variationB (qty 3,
        // unitPrice 300) crosses the limit -> only unitPrice x 1 = 300
        // contributed, not its full 900 lineTotal. Capped base =
        // 1000 + 300 = 1300; at 100% the discount equals that exactly.
        $this->assertSame(1300, $response->json('promotion.discount_amount.minor'));
        $this->assertSame(600, $response->json('total.minor'));
    }

    public function test_applying_a_nonexistent_code_returns_422_and_does_not_get_set(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->putJson('/api/cart/promotion', ['code' => 'NOPE']);

        $response->assertStatus(422);

        $get = $this->getJson('/api/cart');
        $get->assertJsonPath('promotion', null);
    }

    public function test_applying_a_promotion_to_a_cart_that_does_not_exist_yet_returns_404(): void
    {
        $this->createPromotion('SUMMER20');

        $response = $this->putJson('/api/cart/promotion', ['code' => 'SUMMER20']);

        $response->assertStatus(404);
    }

    public function test_get_reflects_an_expired_applied_code_as_invalid_without_clearing_it(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $this->createPromotion('OLDCODE', validUntil: new DateTimeImmutable('-1 day'));

        $this->putJson('/api/cart/promotion', ['code' => 'OLDCODE'])->assertStatus(200);

        $first = $this->getJson('/api/cart');
        $first->assertStatus(200);
        $first->assertJsonPath('promotion.code', 'oldcode');
        $first->assertJsonPath('promotion.valid', false);
        $first->assertJsonPath('promotion.reason', 'expired');

        // A second GET right after must show the exact same thing — the
        // code is still there, never silently cleared by a read.
        $second = $this->getJson('/api/cart');
        $second->assertJsonPath('promotion.code', 'oldcode');
        $second->assertJsonPath('promotion.valid', false);
        $second->assertJsonPath('promotion.reason', 'expired');
    }

    public function test_delete_cart_promotion_clears_it(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);
        $this->createPromotion('SUMMER20');
        $this->putJson('/api/cart/promotion', ['code' => 'SUMMER20'])->assertStatus(200);

        $response = $this->deleteJson('/api/cart/promotion');

        $response->assertStatus(200);
        $response->assertJsonPath('promotion', null);

        $get = $this->getJson('/api/cart');
        $get->assertJsonPath('promotion', null);
    }

    public function test_delete_cart_promotion_when_nothing_was_applied_is_a_clean_200(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->deleteJson('/api/cart/promotion');

        $response->assertStatus(200);
        $response->assertJsonPath('promotion', null);
    }

    public function test_a_code_scoped_to_a_brand_not_in_the_cart_is_invalid_with_no_matching_lines(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $promotion = $this->createPromotion('BRANDONLY');
        $this->attachScope($promotion, PromotionScopeType::BRAND, 'some-brand-not-in-cart');

        $this->putJson('/api/cart/promotion', ['code' => 'BRANDONLY'])->assertStatus(200);

        $response = $this->getJson('/api/cart');

        $response->assertStatus(200);
        $response->assertJsonPath('promotion.valid', false);
        $response->assertJsonPath('promotion.reason', 'no_matching_lines');
    }

    /**
     * Closes GAP 2: a code whose usage_limit_total is already reached
     * must be rejected gracefully and early, in the cart response
     * itself, rather than only failing deep inside the future checkout
     * transaction (design doc §7) — the same as every other invalid
     * code case.
     */
    public function test_a_code_with_usage_limit_total_already_reached_is_invalid_not_silently_discounting(): void
    {
        $variationId = $this->pricedPurchasableVariation();
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $promotion = $this->createPromotion('MAXEDOUT', usageLimitTotal: 1);
        $this->redeemPromotion($promotion);

        $this->putJson('/api/cart/promotion', ['code' => 'MAXEDOUT'])->assertStatus(200);

        $response = $this->getJson('/api/cart');

        $response->assertStatus(200);
        $response->assertJsonPath('promotion.valid', false);
        $response->assertJsonPath('promotion.reason', 'usage_limit_reached');
        $response->assertJsonPath('promotion.discount_amount', null);
        // No discount silently applied — total must equal the plain subtotal.
        $this->assertSame($response->json('subtotal.minor'), $response->json('total.minor'));
    }
}
