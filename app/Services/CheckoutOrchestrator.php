<?php

namespace App\Services;

use App\Services\Exceptions\CartClaimLostException;
use App\Services\Exceptions\CartNotFoundForCheckoutException;
use App\Services\Exceptions\EmptyCartException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use DateTimeImmutable;
use EasyCo\Address\Address;
use EasyCo\Cart\Cart;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single transactional order-placement flow — checkout-domain-
 * design.md §8.3 Phase 1 (steps 1-12) ONLY. Payment adapter invocation
 * and Hook::fire('order.placed') are §8.3 Phase 2, deliberately OUTSIDE
 * this class — a future, separate caller's job, not built here.
 *
 * Every building block this assembles already exists and is tested in
 * isolation: CheckoutLinePricer (pricing/profit), ClientResolver,
 * AddressResolver, PromotionValidator/PromotionDiscountCalculator/
 * PromotionUsageContext. This class's own job is purely the assembly
 * order and the transaction boundary, per §8.3's numbered steps.
 */
final class CheckoutOrchestrator
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CheckoutLinePricer $linePricer,
        private readonly PromotionRepository $promotions,
        private readonly PromotionScopeRepository $promotionScopes,
        private readonly PromotionValidator $promotionValidator,
        private readonly PromotionDiscountCalculator $promotionDiscountCalculator,
        private readonly PromotionRedemptionRepository $promotionRedemptions,
        private readonly OrderRepository $orders,
        private readonly ClientResolver $clientResolver,
        private readonly AddressResolver $addressResolver,
        private readonly StockLevelRepository $stockLevels,
        private readonly TransactionRepository $transactions,
    ) {
    }

    /**
     * $placedAt is an explicit required parameter, never an internal
     * now() — same reasoning Order::create() already documents
     * (trivially testable with a fixed instant).
     *
     * @throws CartNotFoundForCheckoutException
     * @throws EmptyCartException
     * @throws PromotionNoLongerValidException
     * @throws \EasyCo\Pricing\Exceptions\PriceNotConfiguredException Propagates uncaught — aborts the transaction (§8.3 step 3).
     * @throws \EasyCo\Inventory\Exceptions\InsufficientStockException Propagates uncaught — aborts the transaction (§8.3 step 7).
     * @throws \App\Services\Exceptions\AddressNotFoundForCheckoutException Propagates uncaught from AddressResolver::resolveExisting().
     */
    public function place(CheckoutInput $input, DateTimeImmutable $placedAt): CheckoutResult
    {
        // Fast path, before opening any transaction — the cheap, common
        // double-submit case. The atomic claim inside the transaction
        // (step 10) is the real race guard, not this.
        $existingOrderId = $this->carts->findOrderIdForCart($input->cartId);

        if ($existingOrderId !== null) {
            return CheckoutResult::alreadyPlaced($this->orders->findById($existingOrderId));
        }

        try {
            return DB::transaction(fn () => $this->placeWithinTransaction($input, $placedAt));
        } catch (CartClaimLostException) {
            // A concurrent request claimed this cart between the fast
            // path above and this attempt's own claim (step 10) —
            // resolve idempotently, same as the fast path.
            $orderId = $this->carts->findOrderIdForCart($input->cartId);

            return CheckoutResult::alreadyPlaced($this->orders->findById($orderId));
        }
    }

    private function placeWithinTransaction(CheckoutInput $input, DateTimeImmutable $placedAt): CheckoutResult
    {
        // Step 1: load the cart, reject empty.
        $cart = $this->carts->findById($input->cartId);

        if ($cart === null) {
            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        if ($cart->isEmpty()) {
            throw new EmptyCartException("Cart \"{$input->cartId}\" has no lines to check out.");
        }

        // Step 2/3: price every line live; PriceNotConfiguredException
        // propagates uncaught, aborting the transaction.
        $currency = DefaultCurrency::get()->code();
        $subtotal = Money::zero($currency);
        $pricingResults = [];

        foreach ($cart->lines() as $line) {
            $result = $this->linePricer->priceLine($line->variationId(), $line->quantity(), $currency);
            $pricingResults[] = $result;
            $subtotal = $subtotal->add($result->amount());
        }

        // Step 3/4: live-revalidate the applied promotion, if any.
        [$appliedPromotion, $discount] = $this->resolvePromotion($cart, $subtotal, $pricingResults, $input->accountId);

        // Step 5: resolve the Address.
        $address = $this->resolveAddress($input);

        // Step 6: resolve the Client.
        $client = $this->clientResolver->resolve($input->accountId, $input->recipientName);

        // Step 7: decrease stock per line; InsufficientStockException
        // propagates uncaught, aborting the transaction.
        foreach ($cart->lines() as $line) {
            $this->stockLevels->decrease($line->variationId(), $line->quantity());
        }

        // Step 8: Transaction + one SaleLine per line.
        $transaction = new Transaction(null, Channel::WEB);

        foreach ($pricingResults as $result) {
            $transaction->addSaleLine(new SaleLine(
                id: null,
                transactionId: '',
                clientId: $client->id(),
                priceableId: $result->variationId(),
                type: SaleLineType::SALE,
                status: SaleLineStatus::COMPLETED,
                quantity: $result->quantity(),
                amount: $result->amount(),
                profit: $result->profit(),
                recordedAt: $placedAt,
                effectiveAt: $placedAt,
            ));
        }

        $this->transactions->save($transaction);

        // Step 9: the Order itself, snapshotting the resolved Address.
        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: $input->email,
            currency: $currency,
            subtotal: $subtotal,
            discount: $discount,
            deliveryType: OrderDeliveryType::from($address->deliveryType()->value),
            recipientName: $address->recipientName(),
            phone: $address->phone(),
            placedAt: $placedAt,
            accountId: $input->accountId,
            appliedPromotionCode: $appliedPromotion?->code(),
            addressId: $address->id(),
            country: $address->country(),
            city: $address->city(),
            postalCode: $address->postalCode(),
            addressLine1: $address->addressLine1(),
            addressLine2: $address->addressLine2(),
            carrierCode: $address->carrierCode(),
            pickupPointReference: $address->pickupPointReference(),
            settlement: $address->settlement(),
        );

        $this->orders->save($order);

        // Step 10: claim the cart — zero-affected-rows means a
        // concurrent request already claimed it; unwind via rollback
        // and resolve idempotently outside the transaction.
        if (! $this->carts->claimForOrder($cart->id(), $order->id())) {
            throw new CartClaimLostException();
        }

        // Step 11: PromotionRedemption, only if a promotion was applied
        // — locked and re-checked, the authoritative check (§7),
        // distinct from the earlier soft one PromotionValidator ran.
        if ($appliedPromotion !== null) {
            $this->redeemPromotionAtomically($appliedPromotion, $order->id(), $input->accountId, $placedAt);
        }

        return CheckoutResult::placed($order);
    }

    /**
     * Mirrors CartController::resolvePromotion()'s PromotionUsageContext
     * assembly exactly (same per-setting query guards) — do not write a
     * second, differently-guarded assembly.
     *
     * @param array<int, CheckoutLinePricingResult> $pricingResults
     * @return array{0: ?Promotion, 1: Money} [appliedPromotion, discount]
     */
    private function resolvePromotion(
        Cart $cart,
        Money $subtotal,
        array $pricingResults,
        ?string $accountId,
    ): array {
        $code = $cart->appliedPromotionCode();

        if ($code === null) {
            return [null, Money::zero($subtotal->currency())];
        }

        $promotion = $this->promotions->findByCode($code);

        if ($promotion === null) {
            throw new PromotionNoLongerValidException($code, 'not_found');
        }

        $scopes = $this->promotionScopes->findByPromotionId($promotion->id());

        $usage = new PromotionUsageContext(
            customerHasPreviousOrders: $promotion->newCustomersOnly()
                && $accountId !== null
                && $this->orders->hasAnyForAccount($accountId),
            redemptionsTotal: $promotion->usageLimitTotal() !== null
                ? $this->promotionRedemptions->countForPromotion($promotion->id())
                : 0,
            redemptionsForAccount: $promotion->usageLimitPerCustomer() !== null && $accountId !== null
                ? $this->promotionRedemptions->countForPromotionAndAccount($promotion->id(), $accountId)
                : 0,
        );

        $validatorLines = array_map(static fn (CheckoutLinePricingResult $result) => [
            'variationId' => $result->variationId(),
            'quantity' => $result->quantity(),
            'unitPrice' => $result->unitPrice(),
            'lineTotal' => $result->amount(),
            'productId' => $result->productId(),
            'matchingScopeReferenceIds' => $result->matchingScopeReferenceIds(),
            'isDiscounted' => $result->isDiscounted(),
        ], $pricingResults);

        $validation = $this->promotionValidator->validate($promotion, $scopes, $subtotal, $accountId, $validatorLines, $usage);

        if (! $validation->isValid()) {
            throw new PromotionNoLongerValidException($code, $validation->reason());
        }

        $applicableIds = array_flip($validation->applicableVariationIds());
        $applicableLines = array_values(array_filter(
            $validatorLines,
            static fn (array $line) => isset($applicableIds[$line['variationId']])
        ));

        $discountResult = $this->promotionDiscountCalculator->calculate($promotion, $applicableLines);

        return [$promotion, $discountResult->amount()];
    }

    private function resolveAddress(CheckoutInput $input): Address
    {
        if ($input->addressId !== null) {
            if ($input->accountId === null) {
                // Impossible per §8.4 — guests have no saved addresses —
                // but a caller contract violation must fail loudly, not
                // silently pass null into an account-only method.
                throw new InvalidArgumentException(
                    'CheckoutInput::$addressId requires a non-null accountId; guests have no saved addresses.'
                );
            }

            return $this->addressResolver->resolveExisting($input->addressId, $input->accountId);
        }

        if ($input->deliveryType === null) {
            throw new InvalidArgumentException(
                'CheckoutInput::$deliveryType is required when $addressId is null.'
            );
        }

        return $this->addressResolver->resolveNew(
            deliveryType: $input->deliveryType,
            recipientName: $input->recipientName,
            phone: $input->phone,
            accountId: $input->accountId,
            country: $input->country,
            city: $input->city,
            postalCode: $input->postalCode,
            addressLine1: $input->addressLine1,
            addressLine2: $input->addressLine2,
            carrierCode: $input->carrierCode,
            pickupPointReference: $input->pickupPointReference,
            settlement: $input->settlement,
        );
    }

    /**
     * The authoritative usage-limit enforcement, per §7: locks the
     * Promotion row, re-counts existing redemptions against both limits,
     * and only inserts if both still hold — a weaker guarantee than a
     * true DB constraint (depends on every future caller using this
     * transaction correctly), stated plainly, matching §7's own posture.
     */
    private function redeemPromotionAtomically(
        Promotion $promotion,
        string $orderId,
        ?string $accountId,
        DateTimeImmutable $placedAt,
    ): void {
        DB::table('promotions')->where('id', $promotion->id())->lockForUpdate()->first();

        if ($promotion->usageLimitTotal() !== null) {
            $count = $this->promotionRedemptions->countForPromotion($promotion->id());

            if ($count >= $promotion->usageLimitTotal()) {
                throw new PromotionNoLongerValidException($promotion->code(), 'usage_limit_reached');
            }
        }

        if ($promotion->usageLimitPerCustomer() !== null && $accountId !== null) {
            $countForAccount = $this->promotionRedemptions->countForPromotionAndAccount($promotion->id(), $accountId);

            if ($countForAccount >= $promotion->usageLimitPerCustomer()) {
                throw new PromotionNoLongerValidException($promotion->code(), 'usage_limit_per_customer_reached');
            }
        }

        $redemption = new PromotionRedemption(
            id: null,
            promotionId: $promotion->id(),
            orderId: $orderId,
            accountId: $accountId,
            redeemedAt: $placedAt,
        );

        $this->promotionRedemptions->save($redemption);
    }
}
