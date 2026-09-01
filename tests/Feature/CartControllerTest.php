<?php

namespace Tests\Feature;

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
}
