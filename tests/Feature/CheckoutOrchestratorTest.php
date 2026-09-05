<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\CartNotFoundForCheckoutException;
use App\Services\Exceptions\EmptyCartException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\UnknownPaymentMethodException;
use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
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
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real MySQL, real transaction, real everything — checkout-domain-
 * design.md §11's testing plan. Fixture helpers mirror
 * CartControllerTest's own shapes deliberately (same product/price/stock/
 * promotion construction), not reinvented, per this task's own
 * instruction.
 */
class CheckoutOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
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
        ?int $usageLimitTotal = null,
        ?int $usageLimitPerCustomer = null,
        bool $newCustomersOnly = false,
    ): Promotion {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $percentageBasisPoints,
            validFrom: $validFrom,
            validUntil: $validUntil,
            newCustomersOnly: $newCustomersOnly,
            usageLimitTotal: $usageLimitTotal,
            usageLimitPerCustomer: $usageLimitPerCustomer,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    /** Same Client -> Transaction -> Order chain CartControllerTest's own createOrder() uses. */
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

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return AccountModel::findOrFail($account->id());
    }

    private function guestCart(): Cart
    {
        return Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
    }

    private function accountCart(string $accountId): Cart
    {
        return Cart::forAccount($accountId, new DateTimeImmutable('+30 days'));
    }

    private function addLine(Cart $cart, string $variationId, int $quantity): void
    {
        app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
    }

    private function applyPromotion(Cart $cart, string $code): void
    {
        $cart->applyPromotionCode($code);
        app(CartRepository::class)->save($cart);
    }

    private function guestCheckoutInput(string $cartId, array $overrides = []): CheckoutInput
    {
        return new CheckoutInput(
            cartId: $cartId,
            email: $overrides['email'] ?? 'guest@example.com',
            recipientName: $overrides['recipientName'] ?? 'Guest Buyer',
            phone: $overrides['phone'] ?? '+359888000000',
            paymentMethod: $overrides['paymentMethod'] ?? 'cash_on_delivery',
            accountId: null,
            addressId: null,
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
    }

    public function test_a_full_guest_checkout_places_a_real_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 2);

        $placedAt = new DateTimeImmutable('2026-09-05 12:00:00');
        $result = app(CheckoutOrchestrator::class)->place($this->guestCheckoutInput($cart->id()), $placedAt);

        $this->assertFalse($result->isAlreadyPlaced());

        $order = $result->order();
        $this->assertNotNull($order->id());
        $this->assertNull($order->accountId());
        $this->assertSame('guest@example.com', $order->email());
        $this->assertSame(2000, $order->subtotal()->minorValue());
        $this->assertSame(0, $order->discount()->minorValue());
        $this->assertSame(2000, $order->total()->minorValue());
        $this->assertSame($placedAt->getTimestamp(), $order->placedAt()->getTimestamp());

        // Stock genuinely decremented — re-read from the DB.
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());

        // The cart's order_id genuinely set.
        $this->assertSame($order->id(), app(CartRepository::class)->findOrderIdForCart($cart->id()));

        // A real Transaction with one SaleLine, right amount and profit.
        $transaction = app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId());
        $this->assertNotNull($transaction);
        $saleLines = $transaction->saleLines();
        $this->assertCount(1, $saleLines);
        $this->assertSame(2000, $saleLines[0]->amount()->minorValue());
        $this->assertSame(2000, $saleLines[0]->profit()->minorValue());
        $this->assertSame(SaleLineStatus::COMPLETED, $saleLines[0]->status());
    }

    public function test_a_full_logged_in_checkout_with_a_saved_address_places_a_real_order(): void
    {
        $accountModel = $this->loggedInAccount();
        $accountId = (string) $accountModel->id;

        $savedAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888111222',
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Graf Ignatiev 5',
        );
        app(AddressRepository::class)->save($savedAddress);

        $variationId = $this->pricedPurchasableVariation('15.00', 5);
        $cart = $this->accountCart($accountId);
        $this->addLine($cart, $variationId, 1);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            email: $accountModel->email,
            recipientName: 'Ivan Ivanov',
            phone: '+359888111222',
            paymentMethod: 'cash_on_delivery',
            accountId: $accountId,
            addressId: $savedAddress->id(),
        );

        $result = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 13:00:00'));
        $order = $result->order();

        $this->assertFalse($result->isAlreadyPlaced());
        $this->assertSame($accountId, $order->accountId());
        $this->assertSame($savedAddress->id(), $order->addressId());
        $this->assertSame('Graf Ignatiev 5', $order->addressLine1());
        $this->assertSame(1500, $order->subtotal()->minorValue());

        $client = app(ClientRepository::class)->findByAccountId($accountId);
        $this->assertNotNull($client);

        $transaction = app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId());
        $this->assertSame($client->id(), $transaction->saleLines()[0]->clientId());
    }

    public function test_double_submit_produces_exactly_one_order_and_decrements_stock_once(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 3);

        $input = $this->guestCheckoutInput($cart->id());
        $placedAt = new DateTimeImmutable('2026-09-05 12:00:00');

        $first = app(CheckoutOrchestrator::class)->place($input, $placedAt);
        $second = app(CheckoutOrchestrator::class)->place($input, $placedAt);

        $this->assertFalse($first->isAlreadyPlaced());
        $this->assertTrue($second->isAlreadyPlaced());
        $this->assertSame($first->order()->id(), $second->order()->id());
        $this->assertSame(1, OrderModel::count());
        $this->assertSame(7, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_insufficient_stock_at_finalization_aborts_the_whole_transaction(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 5);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 5);

        // Sold out by another concurrent transaction after add-to-cart.
        $this->setStock($variationId, 2);

        $input = $this->guestCheckoutInput($cart->id());

        try {
            app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(0, OrderModel::count());
        $this->assertNull(app(CartRepository::class)->findOrderIdForCart($cart->id()));
        $this->assertSame(2, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_an_expired_promotion_code_aborts_checkout(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $this->createPromotion('EXPIRED10', validUntil: new DateTimeImmutable('2020-01-01'));
        $this->applyPromotion($cart, 'EXPIRED10');

        $input = $this->guestCheckoutInput($cart->id());

        try {
            app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
            $this->fail('Expected PromotionNoLongerValidException.');
        } catch (PromotionNoLongerValidException) {
            // expected
        }

        $this->assertSame(0, OrderModel::count());
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_a_promotion_code_at_its_usage_limit_total_aborts_checkout(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $promotion = $this->createPromotion('LIMIT1', usageLimitTotal: 1);
        $this->redeemPromotion($promotion);
        $this->applyPromotion($cart, 'LIMIT1');

        $input = $this->guestCheckoutInput($cart->id());

        try {
            app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
            $this->fail('Expected PromotionNoLongerValidException.');
        } catch (PromotionNoLongerValidException) {
            // expected
        }

        // Only the fixture's own redemption-supporting order exists.
        $this->assertSame(1, OrderModel::count());
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_a_valid_promotion_produces_a_discounted_order_and_a_redemption_row(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $promotion = $this->createPromotion('TENOFF', percentageBasisPoints: 1000);
        $this->applyPromotion($cart, 'TENOFF');

        $input = $this->guestCheckoutInput($cart->id());
        $result = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
        $order = $result->order();

        $this->assertSame(1000, $order->subtotal()->minorValue());
        $this->assertSame(100, $order->discount()->minorValue());
        $this->assertSame(900, $order->total()->minorValue());
        $this->assertSame('tenoff', $order->appliedPromotionCode());
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
    }

    public function test_an_empty_cart_throws(): void
    {
        $cart = $this->guestCart();
        app(CartRepository::class)->save($cart);

        $this->expectException(EmptyCartException::class);

        app(CheckoutOrchestrator::class)->place($this->guestCheckoutInput($cart->id()), new DateTimeImmutable());
    }

    public function test_an_unknown_cart_id_throws(): void
    {
        $this->expectException(CartNotFoundForCheckoutException::class);

        app(CheckoutOrchestrator::class)->place($this->guestCheckoutInput('999999'), new DateTimeImmutable());
    }

    public function test_cash_on_delivery_produces_a_real_pending_payment_for_the_order_total_not_subtotal(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $this->createPromotion('TENOFF', percentageBasisPoints: 1000);
        $this->applyPromotion($cart, 'TENOFF');

        $input = $this->guestCheckoutInput($cart->id(), ['paymentMethod' => 'cash_on_delivery']);
        $result = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
        $order = $result->order();

        // Subtotal 1000, discount 100, total 900 — the assertion below
        // only proves something because subtotal and total genuinely
        // differ here.
        $this->assertSame(1000, $order->subtotal()->minorValue());
        $this->assertSame(900, $order->total()->minorValue());

        $payment = $result->payment();
        $this->assertNotNull($payment);
        $this->assertSame($order->id(), $payment->orderId());
        $this->assertSame('cash_on_delivery', $payment->method());
        $this->assertSame(900, $payment->amount()->minorValue());
        $this->assertSame(PaymentStatus::PENDING, $payment->status());

        $this->assertCount(1, app(PaymentRepository::class)->findByOrderId($order->id()));
    }

    public function test_bank_transfer_produces_a_real_pending_payment_for_the_order_total_not_subtotal(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        $this->createPromotion('TENOFF', percentageBasisPoints: 1000);
        $this->applyPromotion($cart, 'TENOFF');

        $input = $this->guestCheckoutInput($cart->id(), ['paymentMethod' => 'bank_transfer']);
        $result = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
        $order = $result->order();

        $this->assertSame(1000, $order->subtotal()->minorValue());
        $this->assertSame(900, $order->total()->minorValue());

        $payment = $result->payment();
        $this->assertNotNull($payment);
        $this->assertSame($order->id(), $payment->orderId());
        $this->assertSame('bank_transfer', $payment->method());
        $this->assertSame(900, $payment->amount()->minorValue());
        $this->assertSame(PaymentStatus::PENDING, $payment->status());
    }

    public function test_double_submit_never_produces_a_second_charge(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 3);

        $input = $this->guestCheckoutInput($cart->id());
        $placedAt = new DateTimeImmutable('2026-09-05 12:00:00');

        $first = app(CheckoutOrchestrator::class)->place($input, $placedAt);
        $second = app(CheckoutOrchestrator::class)->place($input, $placedAt);

        $this->assertNotNull($first->payment());
        $this->assertTrue($second->isAlreadyPlaced());
        $this->assertNull($second->payment());
        $this->assertCount(1, app(PaymentRepository::class)->findByOrderId($first->order()->id()));
    }

    public function test_an_unknown_payment_method_throws_but_phase_ones_committed_order_and_stock_decrement_survive(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 2);

        $input = $this->guestCheckoutInput($cart->id(), ['paymentMethod' => 'bitcoin']);

        try {
            app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-05 12:00:00'));
            $this->fail('Expected UnknownPaymentMethodException.');
        } catch (UnknownPaymentMethodException) {
            // expected
        }

        // Phase 2 runs AFTER Phase 1's transaction has already committed
        // — this is the documented Phase-1/Phase-2 boundary, not a bug:
        // the Order and stock decrement survive a Phase 2 failure.
        $this->assertSame(1, OrderModel::count());
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
        $this->assertNotNull(app(CartRepository::class)->findOrderIdForCart($cart->id()));
    }

    public function test_order_placed_hook_fires_with_the_placed_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);

        $received = null;
        Hook::action('order.placed', function ($order) use (&$received): void {
            $received = $order;
        });

        $result = app(CheckoutOrchestrator::class)->place($this->guestCheckoutInput($cart->id()), new DateTimeImmutable('2026-09-05 12:00:00'));

        $this->assertNotNull($received);
        $this->assertSame($result->order()->id(), $received->id());
    }
}
