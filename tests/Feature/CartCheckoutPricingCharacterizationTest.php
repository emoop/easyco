<?php

namespace Tests\Feature;

use DateTimeImmutable;
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
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shipping stage 3.0c (owner decision D6): CHARACTERIZATION of the two cart
 * pricing pipelines — the cart preview (GET /api/cart) and checkout
 * (POST /api/checkout) — through the real HTTP surface, so the numbers asserted
 * are the numbers a customer sees.
 *
 * Written BEFORE the shared CartPricing service was extracted and passing on the
 * unchanged code; it must keep passing, unchanged, after. Every expected number
 * is worked out by hand in the scenario's comment.
 *
 * What differs between the two paths is INTENTIONAL and pinned here:
 *  - an unpriced line: the cart skips it and flags it (price_available false),
 *    checkout refuses (409 price_not_available) and writes nothing;
 *  - a promotion that is not valid: the cart reports it (valid false + reason)
 *    and charges the plain subtotal, checkout refuses (422
 *    promotion_no_longer_valid) and writes nothing.
 *
 * Single-currency by construction (DefaultCurrency): "mixed currencies" cannot
 * occur inside one cart, but a price held only in another currency is the
 * nearest case and is covered as an unpriced line.
 */
class CartCheckoutPricingCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;
    private ?PriceList $priceList = null;
    private string $cartId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- fixtures ------------------------------------------------------------------------------

    private function variation(string $price, int $stock = 50): string
    {
        self::$counter++;
        $n = self::$counter;

        $product = Product::createSimple("Char Product {$n}", "CHAR-{$n}", "char-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->setPrice($variationId, $price, 'EUR');
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function setPrice(string $variationId, string $decimal, string $currency): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimal, $currency), 0),
        ));
    }

    private function removePrices(string $variationId): void
    {
        DB::table('pricing_price_list_items')->where('target_id', $variationId)->delete();
    }

    private function promotion(
        string $code,
        PromotionDiscountType $type = PromotionDiscountType::PERCENTAGE,
        ?int $basisPoints = null,
        ?Money $amount = null,
        ?Money $minimumSpend = null,
        ?int $usageLimitItems = null,
    ): void {
        app(PromotionRepository::class)->save(Promotion::create(
            code: $code,
            discountType: $type,
            percentageBasisPoints: $basisPoints,
            discountAmount: $amount,
            minimumSpend: $minimumSpend,
            usageLimitItems: $usageLimitItems,
        ));
    }

    /** @param array<int, array{0: string, 1: int}> $lines [variationId, quantity] */
    private function fillCart(array $lines, ?string $promotionCode = null): void
    {
        foreach ($lines as [$variationId, $quantity]) {
            $this->cartId = (string) $this->postJson('/api/cart/lines', [
                'variation_id' => $variationId,
                'quantity' => $quantity,
            ])->assertStatus(201)->json('cart_id');
        }

        if ($promotionCode !== null) {
            $this->putJson('/api/cart/promotion', ['code' => $promotionCode])->assertStatus(200);
        }
    }

    private function preview(): array
    {
        return $this->getJson('/api/cart')->assertStatus(200)->json();
    }

    private function checkout(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/checkout', [
            'cart_id' => $this->cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
        ]);
    }

    /** @return array{0: int, 1: int, 2: int} the order's subtotal, discount, total in minor units */
    private function placedNumbers(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertStatus(201);

        return [
            $response->json('order.subtotal.minor'),
            $response->json('order.discount_amount.minor'),
            $response->json('order.total.minor'),
        ];
    }

    private function assertNothingPlaced(): void
    {
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('operational_sales_transactions')->count());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->count());
        $this->assertNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'), 'the cart is not claimed');
    }

    // --- 1. no promotion -----------------------------------------------------------------------

    /** 10.00 x 2 = 2000; 7.55 x 3 = 2265; subtotal 4265; no discount. */
    public function test_no_promotion(): void
    {
        $a = $this->variation('10.00');
        $b = $this->variation('7.55');
        $this->fillCart([[$a, 2], [$b, 3]]);

        $cart = $this->preview();
        $this->assertSame(4265, $cart['subtotal']['minor']);
        $this->assertSame(4265, $cart['total']['minor']);
        $this->assertNull($cart['promotion']);
        $this->assertSame([1000, 755], array_column(array_column($cart['lines'], 'unit_price'), 'minor'));
        $this->assertSame([2000, 2265], array_column(array_column($cart['lines'], 'line_total'), 'minor'));
        $this->assertSame([true, true], array_column($cart['lines'], 'price_available'));

        $this->assertSame([4265, 0, 4265], $this->placedNumbers($this->checkout()));
    }

    // --- 2/3. a percentage and a fixed promotion -------------------------------------------------

    /** 15% of 4265 = 639.75, rounded half up to 640 (PromotionDiscountCalculator::roundedDivide); total 3625. */
    public function test_a_percentage_promotion_rounds_half_up(): void
    {
        $this->promotion('PCT15', basisPoints: 1500);
        $a = $this->variation('10.00');
        $b = $this->variation('7.55');
        $this->fillCart([[$a, 2], [$b, 3]], 'PCT15');

        $cart = $this->preview();
        $this->assertSame(4265, $cart['subtotal']['minor']);
        $this->assertSame(3625, $cart['total']['minor']);
        $this->assertTrue($cart['promotion']['valid']);
        $this->assertSame(640, $cart['promotion']['discount_amount']['minor']);
        $this->assertFalse($cart['promotion']['discount_capped']);
        $this->assertCount(2, $cart['promotion']['applicable_variation_ids']);

        $this->assertSame([4265, 640, 3625], $this->placedNumbers($this->checkout()));
        $this->assertSame(640, (int) DB::table('operational_sales_sale_lines')->sum('promotion_discount_share_minor'));
    }

    /** Fixed 5.00 = 500 off 4265: total 3765, not capped. */
    public function test_a_fixed_promotion_below_the_base(): void
    {
        $this->promotion('FIX5', PromotionDiscountType::FIXED_AMOUNT, amount: Money::fromDecimal('5.00', 'EUR'));
        $a = $this->variation('10.00');
        $b = $this->variation('7.55');
        $this->fillCart([[$a, 2], [$b, 3]], 'FIX5');

        $cart = $this->preview();
        $this->assertSame(3765, $cart['total']['minor']);
        $this->assertSame(500, $cart['promotion']['discount_amount']['minor']);
        $this->assertFalse($cart['promotion']['discount_capped']);

        $this->assertSame([4265, 500, 3765], $this->placedNumbers($this->checkout()));
    }

    // --- 4. a capped promotion -------------------------------------------------------------------

    /**
     * A x1 (1000) + C x2 (666) = 1666. Fixed 20.00 (2000) limited to 1 item: only A's unit is
     * eligible (base 1000), so the 2000 is capped at 1000; total 666.
     */
    public function test_a_fixed_promotion_larger_than_the_base_is_capped(): void
    {
        $this->promotion('FIX20', PromotionDiscountType::FIXED_AMOUNT, amount: Money::fromDecimal('20.00', 'EUR'), usageLimitItems: 1);
        $a = $this->variation('10.00');
        $c = $this->variation('3.33');
        $this->fillCart([[$a, 1], [$c, 2]], 'FIX20');

        $cart = $this->preview();
        $this->assertSame(1666, $cart['subtotal']['minor']);
        $this->assertSame(666, $cart['total']['minor']);
        $this->assertSame(1000, $cart['promotion']['discount_amount']['minor']);
        $this->assertTrue($cart['promotion']['discount_capped']);
        $this->assertSame(2000, $cart['promotion']['nominal_discount_amount']['minor']);

        $this->assertSame([1666, 1000, 666], $this->placedNumbers($this->checkout()));
        $this->assertSame([1000, 0], DB::table('operational_sales_sale_lines')->orderBy('id')->pluck('promotion_discount_share_minor')->map(fn ($v) => (int) $v)->all());
    }

    /**
     * A cart discounted to exactly zero is previewed fine, but cannot be placed because Payment refuses a
     * zero amount. It USED to 500 (a known gap pinned here); since shipping stage 4c it is a controlled
     * 422 `zero_total` (owner decision O8) and nothing is written.
     */
    public function test_a_cart_discounted_to_zero_previews_but_checkout_fails_today(): void
    {
        $this->promotion('FIX20', PromotionDiscountType::FIXED_AMOUNT, amount: Money::fromDecimal('20.00', 'EUR'));
        $a = $this->variation('10.00');
        $c = $this->variation('3.33');
        $this->fillCart([[$a, 1], [$c, 1]], 'FIX20');

        $cart = $this->preview();
        $this->assertSame(1333, $cart['subtotal']['minor']);
        $this->assertSame(0, $cart['total']['minor']);
        $this->assertSame(1333, $cart['promotion']['discount_amount']['minor']);
        $this->assertTrue($cart['promotion']['discount_capped']);

        $this->checkout()->assertStatus(422)->assertJsonPath('reason', 'zero_total');
        $this->assertSame(0, DB::table('orders')->count(), 'nothing was written');
    }

    // --- 5. usage_limit_items partially covering lines -------------------------------------------

    /**
     * 10% off, at most 3 items: A x2 is fully eligible (2000, 1 item left), B x3 only 1 unit
     * (755) => base 2755; 2755 x 10% = 275.5 rounds half up to 276; total 4265 - 276 = 3989.
     */
    public function test_usage_limit_items_discounts_only_the_first_items(): void
    {
        $this->promotion('LIM3', basisPoints: 1000, usageLimitItems: 3);
        $a = $this->variation('10.00');
        $b = $this->variation('7.55');
        $this->fillCart([[$a, 2], [$b, 3]], 'LIM3');

        $cart = $this->preview();
        $this->assertSame(276, $cart['promotion']['discount_amount']['minor']);
        $this->assertSame(3989, $cart['total']['minor']);

        $this->assertSame([4265, 276, 3989], $this->placedNumbers($this->checkout()));

        // The discount is split over the lines in proportion to their eligible amounts and sums back to it.
        $shares = DB::table('operational_sales_sale_lines')->orderBy('id')->pluck('promotion_discount_share_minor')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(276, array_sum($shares));
        $this->assertSame([200, 76], $shares, '276 over weights 2000 : 755');
    }

    // --- 6. an unpriced line (INTENTIONAL difference) --------------------------------------------

    /** The cart skips the unpriced line (subtotal 2000, not 2000 + something); checkout refuses and writes nothing. */
    public function test_an_unpriced_line_is_skipped_by_the_cart_and_refused_by_checkout(): void
    {
        $a = $this->variation('10.00');
        $d = $this->variation('4.00');
        $this->fillCart([[$a, 2], [$d, 1]]);
        $this->removePrices($d);

        $cart = $this->preview();
        $this->assertSame(2000, $cart['subtotal']['minor']);
        $this->assertSame(2000, $cart['total']['minor']);
        $this->assertSame([true, false], array_column($cart['lines'], 'price_available'));
        $this->assertNull($cart['lines'][1]['unit_price']);
        $this->assertNull($cart['lines'][1]['line_total']);

        $this->checkout()->assertStatus(409)->assertJsonPath('reason', 'price_not_available');
        $this->assertNothingPlaced();
    }

    /** The promotion in the cart is calculated on the PRICED lines only; checkout still refuses. */
    public function test_the_cart_discounts_only_priced_lines_and_checkout_still_refuses(): void
    {
        $this->promotion('PCT10', basisPoints: 1000);
        $a = $this->variation('10.00');
        $d = $this->variation('4.00');
        $this->fillCart([[$a, 2], [$d, 1]], 'PCT10');
        $this->removePrices($d);

        $cart = $this->preview();
        $this->assertSame(2000, $cart['subtotal']['minor']);
        $this->assertSame(200, $cart['promotion']['discount_amount']['minor']);
        $this->assertSame(1800, $cart['total']['minor']);

        $this->checkout()->assertStatus(409)->assertJsonPath('reason', 'price_not_available');
        $this->assertNothingPlaced();
    }

    /**
     * A price held in another currency is NOT treated as "no price" — the resolver returns it and adding
     * it to the EUR subtotal throws, so the PREVIEW still 500s (not part of stage 4c). Checkout USED to 500
     * too; since shipping stage 4c it is a controlled 422 `currency_mismatch` and nothing is written.
     */
    public function test_a_price_held_in_another_currency_fails_on_both_paths_today(): void
    {
        $a = $this->variation('10.00');
        $d = $this->variation('4.00');
        $this->fillCart([[$a, 1], [$d, 1]]);
        $this->removePrices($d);
        $this->setPrice($d, '4.00', 'USD');

        $this->getJson('/api/cart')->assertStatus(500);
        $this->checkout()->assertStatus(422)->assertJsonPath('reason', 'currency_mismatch');
        $this->assertSame(0, DB::table('orders')->count());
    }

    // --- 7. a promotion that is not valid (INTENTIONAL difference) -------------------------------

    /** @return array<string, array{string}> */
    public static function invalidations(): array
    {
        return [
            'expired after it was applied' => ['expired'],
            'deleted after it was applied' => ['not_found'],
            'minimum spend not met' => ['minimum_spend_not_met'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidations')]
    public function test_an_invalid_promotion_is_reported_by_the_cart_and_refused_by_checkout(string $reason): void
    {
        $this->promotion('GONE10', basisPoints: 1000, minimumSpend: $reason === 'minimum_spend_not_met' ? Money::fromDecimal('500.00', 'EUR') : null);
        $a = $this->variation('10.00');
        $this->fillCart([[$a, 2]], 'GONE10');

        if ($reason === 'expired') {
            DB::table('promotions')->where('code', 'GONE10')->update(['valid_until' => (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
        }

        if ($reason === 'not_found') {
            DB::table('promotions')->where('code', 'GONE10')->delete();
        }

        $cart = $this->preview();
        $this->assertSame(2000, $cart['subtotal']['minor']);
        $this->assertSame(2000, $cart['total']['minor'], 'an invalid code charges the plain subtotal');
        $this->assertFalse($cart['promotion']['valid']);
        $this->assertSame($reason, $cart['promotion']['reason']);
        $this->assertNull($cart['promotion']['discount_amount']);
        $this->assertSame('gone10', $cart['promotion']['code'], 'codes are stored lowercase');

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('reason', 'promotion_no_longer_valid');
        $this->assertNothingPlaced();
    }

    // --- 8. the same cart gives the same numbers on both paths -----------------------------------

    /** @return array<string, array{string}> */
    public static function parityScenarios(): array
    {
        return [
            'no promotion' => ['none'],
            'percentage' => ['pct'],
            'fixed' => ['fixed'],
            'capped fixed' => ['capped'],
            'usage limit items' => ['limit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('parityScenarios')]
    public function test_for_the_same_cart_the_preview_and_checkout_give_identical_subtotal_discount_and_goods_after_discount(string $scenario): void
    {
        $a = $this->variation('10.00');
        $b = $this->variation('7.55');
        $c = $this->variation('3.33');

        $code = null;
        $lines = [[$a, 2], [$b, 3]];

        switch ($scenario) {
            case 'pct':
                $this->promotion('P', basisPoints: 1750);
                $code = 'P';
                break;
            case 'fixed':
                $this->promotion('P', PromotionDiscountType::FIXED_AMOUNT, amount: Money::fromDecimal('12.34', 'EUR'));
                $code = 'P';
                break;
            case 'capped':
                $this->promotion('P', PromotionDiscountType::FIXED_AMOUNT, amount: Money::fromDecimal('99.00', 'EUR'), usageLimitItems: 1);
                $lines = [[$a, 1], [$c, 2]];
                $code = 'P';
                break;
            case 'limit':
                $this->promotion('P', basisPoints: 3333, usageLimitItems: 4);
                $code = 'P';
                break;
        }

        $this->fillCart($lines, $code);
        $cart = $this->preview();
        $previewDiscount = $cart['promotion']['discount_amount']['minor'] ?? 0;

        [$subtotal, $discount, $goodsAfterDiscount] = $this->placedNumbers($this->checkout());

        $this->assertSame($cart['subtotal']['minor'], $subtotal, 'subtotal');
        $this->assertSame($previewDiscount, $discount, 'discount');
        $this->assertSame($cart['total']['minor'], $goodsAfterDiscount, 'goods after discount');
        $this->assertSame($subtotal - $discount, $goodsAfterDiscount);
    }

    // --- the shared service's contract -------------------------------------------------------------

    public function test_the_shared_service_opens_no_transaction_and_writes_nothing(): void
    {
        $this->promotion('P', basisPoints: 1000);
        $this->fillCart([[$this->variation('10.00'), 2]], 'P');
        $cart = app(\EasyCo\Cart\Contracts\CartRepository::class)->findById($this->cartId);

        $writes = 0;
        DB::beforeStartingTransaction(function (): void {
            $this->fail('CartPricing must not open a transaction.');
        });
        DB::listen(function ($query) use (&$writes): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $writes++;
            }
        });

        $result = app(\App\Services\CartPricing::class)->price($cart, null, 'EUR', \App\Services\UnpricedLines::REFUSE, includeUnitCost: true);

        $this->assertSame(0, $writes, 'only reads');
        $this->assertSame(2000, $result->subtotal()->minorValue());
        $this->assertSame(200, $result->discount()->minorValue());
        $this->assertSame(1800, $result->goodsAfterDiscount()->minorValue());
        $this->assertSame('p', $result->appliedPromotionCode());
        $this->assertNull($result->promotionRefusal());
    }
}
