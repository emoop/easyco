<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\SaleLineOrderReconciliationException;
use App\Services\SaleLineSnapshotBuilder;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\ProductCost;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * operational-sales-domain-design.md §3.13 stage 4a — CheckoutOrchestrator
 * writing the full snapshot via SaleLineSnapshotBuilder (D1/D3/D6). Fixture
 * helpers mirror CheckoutOrchestratorTest's own shapes deliberately, not
 * reinvented.
 */
class CheckoutSaleLineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    /** @return array{0: string, 1: string} [variationId, productId] */
    private function variationWithProductId(): array
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return [$product->variations()[0]->id(), $product->id()];
    }

    private function setPrice(string $variationId, string $decimalAmount): void
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
    }

    private function setStock(string $variationId, int $quantity = 100): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    /** @return array{0: string, 1: string} [variationId, productId] */
    private function pricedVariation(string $decimalAmount): array
    {
        [$variationId, $productId] = $this->variationWithProductId();
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId);

        return [$variationId, $productId];
    }

    private function setCost(string $variationId, string $decimalAmount): void
    {
        $cost = new ProductCost(id: null, priceableId: $variationId, cost: Money::fromDecimal($decimalAmount, 'EUR'));
        app(ProductCostRepository::class)->save($cost);
    }

    private function guestCart(): Cart
    {
        return Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
    }

    private function addLine(Cart $cart, string $variationId, int $quantity): void
    {
        app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
    }

    private function checkoutInput(string $cartId): CheckoutInput
    {
        return new CheckoutInput(
            cartId: $cartId,
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            accountId: null,
            addressId: null,
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
    }

    private function place(Cart $cart): \App\Services\CheckoutResult
    {
        return app(CheckoutOrchestrator::class)->place($this->checkoutInput($cart->id()), new DateTimeImmutable('2026-09-25 12:00:00'));
    }

    /** @return \EasyCo\OperationalSales\SaleLine[] */
    private function saleLinesFor(Order $order): array
    {
        return app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId())->saleLines();
    }

    /**
     * Promotion scoped to only ONE of two products in the cart: the
     * non-applicable line gets a zero promotionDiscountShare, never
     * skipped/omitted — D3's own "returns a per-line share for EVERY
     * priced line" requirement.
     */
    public function test_a_promotion_scoped_to_one_product_gives_the_other_line_a_zero_share(): void
    {
        [$applicableVariationId, $applicableProductId] = $this->pricedVariation('10.00');
        [$otherVariationId] = $this->pricedVariation('10.00');

        $promotion = Promotion::create(code: 'SCOPED10', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);
        app(PromotionScopeRepository::class)->attach(new PromotionScope(
            id: null,
            promotionId: $promotion->id(),
            scopeType: PromotionScopeType::PRODUCT,
            scopeReferenceId: $applicableProductId,
            mode: PromotionScopeMode::INCLUDE,
        ));

        $cart = $this->guestCart();
        $this->addLine($cart, $applicableVariationId, 1);
        $this->addLine($cart, $otherVariationId, 1);
        $cart->applyPromotionCode('SCOPED10');
        app(CartRepository::class)->save($cart);

        $result = $this->place($cart);
        $order = $result->order();

        $this->assertSame(2000, $order->subtotal()->minorValue());
        $this->assertSame(100, $order->discount()->minorValue());
        $this->assertSame(1900, $order->total()->minorValue());

        $saleLines = $this->saleLinesFor($order);
        $byVariationId = [];
        foreach ($saleLines as $line) {
            $byVariationId[$line->priceableId()] = $line;
        }

        $this->assertSame(100, $byVariationId[$applicableVariationId]->promotionDiscountShare()->minorValue());
        $this->assertSame(0, $byVariationId[$otherVariationId]->promotionDiscountShare()->minorValue());

        $shareSum = $byVariationId[$applicableVariationId]->promotionDiscountShare()
            ->add($byVariationId[$otherVariationId]->promotionDiscountShare());
        $this->assertSame(100, $shareSum->minorValue());

        $netSum = $byVariationId[$applicableVariationId]->netPaidAmount()
            ->add($byVariationId[$otherVariationId]->netPaidAmount());
        $this->assertSame(1900, $netSum->minorValue());
    }

    /**
     * operational-sales-domain-design.md §3.13's own worked counter-
     * example, run through a REAL checkout: three lines with eligible
     * amounts 5, 5, 1 (minor units), a 10% promotion producing a total
     * discount of exactly 1 minor unit. The largest-remainder method
     * gives shares [1, 0, 0] (line 0 wins the tie by array order) —
     * never the old "round each share, last absorbs the remainder" rule,
     * which would have produced a NEGATIVE share on the last line.
     */
    public function test_the_five_five_one_rounding_case_through_a_real_checkout(): void
    {
        [$variationA] = $this->pricedVariation('0.05');
        [$variationB] = $this->pricedVariation('0.05');
        [$variationC] = $this->pricedVariation('0.01');

        $promotion = Promotion::create(code: 'TINY10', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);

        $cart = $this->guestCart();
        $this->addLine($cart, $variationA, 1);
        $this->addLine($cart, $variationB, 1);
        $this->addLine($cart, $variationC, 1);
        $cart->applyPromotionCode('TINY10');
        app(CartRepository::class)->save($cart);

        $result = $this->place($cart);
        $order = $result->order();

        $this->assertSame(11, $order->subtotal()->minorValue());
        $this->assertSame(1, $order->discount()->minorValue());

        $saleLines = $this->saleLinesFor($order);
        $byVariationId = [];
        foreach ($saleLines as $line) {
            $byVariationId[$line->priceableId()] = $line;
        }

        $this->assertSame(1, $byVariationId[$variationA]->promotionDiscountShare()->minorValue());
        $this->assertSame(0, $byVariationId[$variationB]->promotionDiscountShare()->minorValue());
        $this->assertSame(0, $byVariationId[$variationC]->promotionDiscountShare()->minorValue());
        $this->assertGreaterThanOrEqual(0, $byVariationId[$variationC]->netPaidAmount()->minorValue());
    }

    /**
     * usage_limit_items crossing a line: walked in cart order, the line
     * that crosses the limit contributes only unitPrice x remaining to
     * the eligible base — never its full lineTotal — and a line entirely
     * beyond the limit contributes (and shares) zero.
     */
    public function test_usage_limit_items_crossing_a_line_gives_it_a_partial_share(): void
    {
        [$variationA] = $this->pricedVariation('10.00'); // qty 2 -> 2000
        [$variationB] = $this->pricedVariation('5.00');  // qty 3 -> 1500, crosses the limit
        [$variationC] = $this->pricedVariation('5.00');  // qty 1 -> entirely beyond the limit

        $promotion = Promotion::create(
            code: 'LIMIT3',
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            usageLimitItems: 3,
        );
        app(PromotionRepository::class)->save($promotion);

        $cart = $this->guestCart();
        $this->addLine($cart, $variationA, 2);
        $this->addLine($cart, $variationB, 3);
        $this->addLine($cart, $variationC, 1);
        $cart->applyPromotionCode('LIMIT3');
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart)->order();

        // eligible base: 2000 (A, fully within limit) + 500 (B, 1 unit
        // remaining x 5.00) + 0 (C, entirely beyond) = 2500.
        // discount = roundedDivide(2500 * 1000, 10000) = 250.
        $this->assertSame(250, $order->discount()->minorValue());

        $saleLines = $this->saleLinesFor($order);
        $byVariationId = [];
        foreach ($saleLines as $line) {
            $byVariationId[$line->priceableId()] = $line;
        }

        $this->assertSame(200, $byVariationId[$variationA]->promotionDiscountShare()->minorValue());
        $this->assertSame(50, $byVariationId[$variationB]->promotionDiscountShare()->minorValue());
        $this->assertSame(0, $byVariationId[$variationC]->promotionDiscountShare()->minorValue());
    }

    /**
     * D4 — proves the actual behaviour change: profit is now computed on
     * NET (after the promotion share), not on the pre-promotion amount.
     * qty 1, price 10.00, cost 4.00, 10% promotion -> share 100.
     * netPaidAmount = 1000 - 100 = 900; profit = 900 - 400 = 500 — NOT
     * 600 (amount 1000 - cost 400), the old, pre-promotion formula.
     */
    public function test_profit_is_computed_on_net_not_on_pre_promotion_amount(): void
    {
        [$variationId] = $this->pricedVariation('10.00');
        $this->setCost($variationId, '4.00');

        $promotion = Promotion::create(code: 'TENOFF', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $cart->applyPromotionCode('TENOFF');
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart)->order();
        $saleLine = $this->saleLinesFor($order)[0];

        $this->assertSame(100, $saleLine->promotionDiscountShare()->minorValue());
        $this->assertSame(900, $saleLine->netPaidAmount()->minorValue());
        $this->assertSame(500, $saleLine->profit()->minorValue());
        $this->assertNotSame(600, $saleLine->profit()->minorValue());
    }

    /**
     * D6 — a forced reconciliation mismatch (simulated via a corrupting
     * SaleLineSnapshotBuilder binding, since the real allocate()-based
     * pipeline cannot organically produce one — see
     * CheckoutOrchestrator::assertSaleLinesReconcileWithOrder()'s own
     * comment) aborts the whole transaction: nothing is written at all.
     */
    public function test_a_forced_reconciliation_mismatch_aborts_checkout_with_nothing_written(): void
    {
        $this->app->bind(SaleLineSnapshotBuilder::class, function ($app) {
            return new class($app->make(VariationRepository::class)) extends SaleLineSnapshotBuilder {
                public function buildForCart(
                    array $lines,
                    string $transactionId,
                    string $clientId,
                    SaleLineStatus $status,
                    DateTimeImmutable $recordedAt,
                    DateTimeImmutable $effectiveAt,
                ): array {
                    // Corrupts every line's promotionDiscountShare to
                    // zero regardless of what CheckoutOrchestrator
                    // actually computed — forces Σ share != order
                    // discount, since $discount itself is read from the
                    // real, uncorrupted PromotionDiscountResult.
                    foreach ($lines as &$line) {
                        $line['promotionDiscountShare'] = Money::zero($line['finalUnitPrice']->currency()->code());
                    }

                    return parent::buildForCart($lines, $transactionId, $clientId, $status, $recordedAt, $effectiveAt);
                }
            };
        });

        [$variationId] = $this->pricedVariation('10.00');
        $promotion = Promotion::create(code: 'TENOFF', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $cart->applyPromotionCode('TENOFF');
        app(CartRepository::class)->save($cart);

        try {
            $this->place($cart);
            $this->fail('Expected SaleLineOrderReconciliationException.');
        } catch (SaleLineOrderReconciliationException) {
            // expected
        }

        $this->assertSame(0, OrderModel::count());
        $this->assertNull(app(CartRepository::class)->findOrderIdForCart($cart->id()));
        $this->assertSame(100, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    /**
     * "No silent fallbacks" review fix, item 2's own required third
     * proof: a checkout that hits the sold-attributes read's throw path
     * (here: the variation vanishes from VariationRepository::findByIds()
     * between the cart being built and SaleLineSnapshotBuilder reading it
     * — a real, if narrow, race shape, mirroring
     * test_insufficient_stock_at_finalization_aborts_the_whole_transaction's
     * own "sold out by another concurrent transaction" framing) aborts
     * with nothing written — same guarantee as the D6 test above, for a
     * different failure source.
     */
    public function test_a_variation_disappearing_before_the_sold_attributes_read_aborts_checkout_with_nothing_written(): void
    {
        [$variationId] = $this->pricedVariation('10.00');

        $realVariations = app(VariationRepository::class);
        $this->app->bind(VariationRepository::class, fn () => new class($realVariations, $variationId) implements VariationRepository {
            public function __construct(private VariationRepository $real, private string $hiddenVariationId)
            {
            }

            public function findById(string $id): ?Variation
            {
                return $this->real->findById($id);
            }

            public function findBySku(string $sku): ?Variation
            {
                return $this->real->findBySku($sku);
            }

            public function findByBarcode(string $barcode): ?Variation
            {
                return $this->real->findByBarcode($barcode);
            }

            public function findByProductId(string $productId): array
            {
                return $this->real->findByProductId($productId);
            }

            public function findByIds(array $variationIds): array
            {
                $result = $this->real->findByIds($variationIds);
                unset($result[$this->hiddenVariationId]);

                return $result;
            }

            public function updateSortOrders(string $productId, array $orderedVariationIds): void
            {
                $this->real->updateSortOrders($productId, $orderedVariationIds);
            }
        });

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        app(CartRepository::class)->save($cart);

        try {
            $this->place($cart);
            $this->fail('Expected a LogicException from the sold-attributes read.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('was not returned by VariationRepository::findByIds()', $e->getMessage());
        }

        $this->assertSame(0, OrderModel::count());
        $this->assertNull(app(CartRepository::class)->findOrderIdForCart($cart->id()));
        $this->assertSame(100, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }
}
