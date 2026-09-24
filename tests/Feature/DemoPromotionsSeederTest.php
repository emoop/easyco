<?php

namespace Tests\Feature;

use Database\Seeders\DemoPromotionsSeeder;
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
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the real DemoPromotionsSeeder — admin-panel-design.md §14,
 * Commit 5. Real repository writes only, never a raw insert.
 */
class DemoPromotionsSeederTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00'): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Demo Product {$suffix}", "SKU-DEMO-{$suffix}", "demo-product-{$suffix}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

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

    public function test_the_first_run_creates_all_four_codes(): void
    {
        app(DemoPromotionsSeeder::class)->run();

        $promotions = app(PromotionRepository::class);

        $demo10 = $promotions->findByCode('DEMO10');
        $this->assertNotNull($demo10);
        $this->assertSame(PromotionDiscountType::PERCENTAGE, $demo10->discountType());
        $this->assertSame(1000, $demo10->percentageBasisPoints());

        $demofix = $promotions->findByCode('DEMOFIX');
        $this->assertNotNull($demofix);
        $this->assertSame(PromotionDiscountType::FIXED_AMOUNT, $demofix->discountType());
        $this->assertSame('50.00', $demofix->discountAmount()->decimalValue());

        $demoonce = $promotions->findByCode('DEMOONCE');
        $this->assertNotNull($demoonce);
        $this->assertSame(1000, $demoonce->percentageBasisPoints());
        $this->assertSame(1, $demoonce->usageLimitTotal());

        // DEMOSCOPE is skipped (reported), not created, when nothing
        // qualifies — no product/brand exists in this test's DB at all.
        $this->assertNull($promotions->findByCode('DEMOSCOPE'));
    }

    public function test_demoscope_scopes_to_the_brand_with_the_most_non_archived_products(): void
    {
        $brandRepository = app(\EasyCo\Catalog\Contracts\BrandRepository::class);
        $popular = new \EasyCo\Catalog\Brand(id: null, name: 'Popular Brand', slug: 'popular-brand');
        $brandRepository->save($popular);
        $rare = new \EasyCo\Catalog\Brand(id: null, name: 'Rare Brand', slug: 'rare-brand');
        $brandRepository->save($rare);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Popular Product {$i}", "SKU-POP-{$i}", "popular-product-{$i}");
            $product->assignBrand($popular->id());
            app(ProductRepository::class)->save($product);
        }

        $rareProduct = Product::createSimple('Rare Product', 'SKU-RARE', 'rare-product');
        $rareProduct->assignBrand($rare->id());
        app(ProductRepository::class)->save($rareProduct);

        // An archived product for the popular brand must not count.
        $archived = Product::createSimple('Archived Popular Product', 'SKU-POP-ARCH', 'archived-popular-product');
        $archived->assignBrand($popular->id());
        $archived->publish();
        $archived->archive();
        app(ProductRepository::class)->save($archived);

        app(DemoPromotionsSeeder::class)->run();

        $promotion = app(PromotionRepository::class)->findByCode('DEMOSCOPE');
        $this->assertNotNull($promotion);
        $this->assertSame(1500, $promotion->percentageBasisPoints());

        $scopes = app(PromotionScopeRepository::class)->findByPromotionId($promotion->id());
        $this->assertCount(1, $scopes);
        $this->assertSame('brand', $scopes[0]->scopeType()->value);
        $this->assertSame((string) $popular->id(), $scopes[0]->scopeReferenceId());
        $this->assertSame('include', $scopes[0]->mode()->value);
    }

    /**
     * seedScoped() is two separate writes (the Promotion save, then the
     * PromotionScope attach) — before this fix, an attach() failure left a
     * real, already-committed DEMOSCOPE promotion behind with no scope on
     * it at all, silently breaking "scoped to one brand" (the entire reason
     * DEMOSCOPE exists). Now both writes share one DB::transaction(), so a
     * failing attach() rolls the promotion save back too. The failure is
     * forced via a real, bound test fake — not a partial write simulated by
     * hand.
     */
    public function test_if_the_scope_attach_fails_no_demoscope_promotion_is_left_behind(): void
    {
        $popular = new \EasyCo\Catalog\Brand(id: null, name: 'Popular Brand', slug: 'popular-brand');
        app(\EasyCo\Catalog\Contracts\BrandRepository::class)->save($popular);

        $product = Product::createSimple('Popular Product', 'SKU-POP', 'popular-product');
        $product->assignBrand($popular->id());
        app(ProductRepository::class)->save($product);

        $this->app->bind(PromotionScopeRepository::class, ThrowingPromotionScopeRepositoryFake::class);

        try {
            app(DemoPromotionsSeeder::class)->run();
            $this->fail('Expected the throwing PromotionScopeRepository fake to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated attach failure', $e->getMessage());
        }

        $this->assertNull(
            app(PromotionRepository::class)->findByCode('DEMOSCOPE'),
            'DB::transaction() must have rolled the Promotion save back along with the failed attach()'
        );
    }

    public function test_the_second_run_is_a_no_op(): void
    {
        app(DemoPromotionsSeeder::class)->run();
        $firstId = app(PromotionRepository::class)->findByCode('DEMO10')->id();

        app(DemoPromotionsSeeder::class)->run();
        $secondId = app(PromotionRepository::class)->findByCode('DEMO10')->id();

        $this->assertSame($firstId, $secondId);
        $this->assertCount(3, app(PromotionRepository::class)->all(), 'DEMOSCOPE stays skipped in this DB, so only 3 real codes exist');
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        app(DemoPromotionsSeeder::class)->run();

        $this->assertNull(app(PromotionRepository::class)->findByCode('DEMO10'));
    }

    public function test_demo10_applies_in_a_real_cart_via_the_promotion_endpoint(): void
    {
        app(DemoPromotionsSeeder::class)->run();
        $variationId = $this->pricedPurchasableVariation('10.00');

        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201);

        $response = $this->putJson('/api/cart/promotion', ['code' => 'DEMO10']);

        $response->assertStatus(200);
        $response->assertJsonPath('promotion.code', 'demo10');
        $response->assertJsonPath('promotion.valid', true);
        // 10% of 1000 minor units = 100 minor units.
        $this->assertSame(100, $response->json('promotion.discount_amount.minor'));
    }

    /**
     * Both checkouts go through the real CheckoutOrchestrator directly
     * (same fixture shape as CheckoutOrchestratorTest/
     * OrderAdminReaderTest) — not the HTTP cart endpoints, so this test
     * does not depend on guessing how the guest cart token round-trips
     * through the session between two separate requests.
     */
    public function test_a_second_checkout_with_demoonce_fails_with_usage_limit_reached(): void
    {
        app(DemoPromotionsSeeder::class)->run();

        $firstVariationId = $this->pricedPurchasableVariation('10.00');
        $firstCart = \EasyCo\Cart\Cart::forGuest((string) \Illuminate\Support\Str::uuid(), new \DateTimeImmutable('+10 days'));
        app(\EasyCo\Cart\CartLineAdder::class)->addLine($firstCart, $firstVariationId, 1, null, null);
        $firstCart->applyPromotionCode('DEMOONCE');
        app(\EasyCo\Cart\Contracts\CartRepository::class)->save($firstCart);

        $firstInput = new \App\Services\CheckoutInput(
            cartId: $firstCart->id(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: \EasyCo\Address\Enums\AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        app(\App\Services\CheckoutOrchestrator::class)->place($firstInput, new \DateTimeImmutable('2026-09-20 10:00:00'));

        $secondVariationId = $this->pricedPurchasableVariation('10.00');
        $secondCart = \EasyCo\Cart\Cart::forGuest((string) \Illuminate\Support\Str::uuid(), new \DateTimeImmutable('+10 days'));
        app(\EasyCo\Cart\CartLineAdder::class)->addLine($secondCart, $secondVariationId, 1, null, null);
        $secondCart->applyPromotionCode('DEMOONCE');
        app(\EasyCo\Cart\Contracts\CartRepository::class)->save($secondCart);

        $secondInput = new \App\Services\CheckoutInput(
            cartId: $secondCart->id(),
            email: 'guest2@example.com',
            recipientName: 'Guest Buyer Two',
            phone: '+359888000001',
            paymentMethod: 'cash_on_delivery',
            deliveryType: \EasyCo\Address\Enums\AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        // The real, atomic enforcement
        // (CheckoutOrchestrator::redeemPromotionAtomically()) is what
        // must refuse this, not this test re-implementing the rule —
        // the exception carries no separate reason() accessor, so the
        // reason is asserted from its own message text, matching how
        // the exception class itself builds it.
        try {
            app(\App\Services\CheckoutOrchestrator::class)->place($secondInput, new \DateTimeImmutable('2026-09-20 11:00:00'));
            $this->fail('Expected PromotionNoLongerValidException was not thrown.');
        } catch (\App\Services\Exceptions\PromotionNoLongerValidException $e) {
            $this->assertStringContainsString('usage_limit_reached', $e->getMessage());
        }
    }
}

/** Test-only fixture — forces seedScoped()'s attach() to fail, to prove the transaction wrap actually rolls back. */
class ThrowingPromotionScopeRepositoryFake implements PromotionScopeRepository
{
    public function attach(PromotionScope $scope): void
    {
        throw new RuntimeException('simulated attach failure');
    }

    public function detach(string $scopeId): void
    {
    }

    /** @return PromotionScope[] */
    public function findByPromotionId(string $promotionId): array
    {
        return [];
    }
}
