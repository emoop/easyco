<?php

namespace App\Services;

use App\Services\Exceptions\CartClaimLostException;
use App\Services\Exceptions\CartNotFoundForCheckoutException;
use App\Services\Exceptions\EmptyCartException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\SaleLineOrderReconciliationException;
use DateTimeImmutable;
use EasyCo\Address\Address;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartClaim;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentContext;
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
 * The full order-placement flow — checkout-domain-design.md §8.3, both
 * phases:
 * - Phase 1 (steps 1-12): the single DB transaction — load cart, price
 *   lines, revalidate the promotion, resolve Address/Client, decrease
 *   stock, write Transaction/SaleLines/Order, WRITE A PENDING Payment
 *   row, claim the cart, redeem the promotion.
 * - Phase 2 (steps 13-14), OUTSIDE that transaction — charge via the
 *   resolved PaymentMethodAdapter, RECORD ITS ANSWER onto the already-
 *   committed Payment row, then Hook::fire('order.placed'). Per
 *   checkout-orchestration-performance-note.md §2 (external calls need
 *   explicit handling, never block inside a held DB transaction) and
 *   checkout-domain-design.md §8.3's own restatement of it: a DB
 *   transaction is never held open across a call to an external system,
 *   even though V1's two adapters are synchronous and offline — this
 *   shape must already be correct for the day a real provider adapter
 *   replaces them.
 *
 * THE PAYMENT ROW MOVED INTO PHASE 1 — found in review, not part of the
 * original design: a crash (or any uncaught exception) between Phase 1's
 * commit and the old Phase 2 Payment::create()/save() left a real Order
 * with NO Payment row at all — not FAILED, not PENDING, nothing — and a
 * retry hit the idempotent-replay fast path (§6) and never re-ran Phase
 * 2, producing an order the customer could never pay for. Writing the
 * Payment as PENDING inside Phase 1, then having Phase 2 call
 * Payment::recordAttemptResult() to fill in what the adapter actually
 * said, means every committed Order now has a Payment row by
 * construction — a crash in Phase 2 leaves it at PENDING with a NULL
 * attemptedAt, a real, findable state a future reconciliation job can
 * act on, per EasyCo\Payment\Payment's own class docblock.
 *
 * Every building block this assembles already exists and is tested in
 * isolation: CheckoutLinePricer (pricing/profit), ClientResolver,
 * AddressResolver, PromotionValidator/PromotionDiscountCalculator/
 * PromotionUsageContext, PaymentMethodAdapterResolver. This class's own
 * job is purely the assembly order and the two-phase boundary, per
 * §8.3's numbered steps.
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
        private readonly PromotionUsageContextAssembler $usageContextAssembler,
        private readonly OrderRepository $orders,
        private readonly ClientResolver $clientResolver,
        private readonly AddressResolver $addressResolver,
        private readonly StockLevelRepository $stockLevels,
        private readonly TransactionRepository $transactions,
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodAdapterResolver $adapterResolver,
        private readonly SaleLineSnapshotBuilder $saleLineSnapshotBuilder,
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
     * @throws \App\Services\Exceptions\UnknownPaymentMethodException Propagates uncaught from Phase 2 — the Order/stock/Transaction/Payment(PENDING) from Phase 1 have already committed by this point (see this method's own inline note).
     * @throws \EasyCo\Pricing\Exceptions\PriceNotConfiguredException Propagates uncaught — aborts the transaction (§8.3 step 3).
     * @throws \EasyCo\Inventory\Exceptions\InsufficientStockException Propagates uncaught — aborts the transaction (§8.3 step 7).
     * @throws \App\Services\Exceptions\AddressNotFoundForCheckoutException Propagates uncaught from AddressResolver::resolveExisting().
     */
    public function place(CheckoutInput $input, DateTimeImmutable $placedAt): CheckoutResult
    {
        // Fast path, before opening any transaction — the cheap, common
        // double-submit case. The atomic claim inside the transaction
        // (step 10) is the real race guard, not this.
        //
        // IDENTITY-CHECKED, NEVER A BARE CART ID (cart-domain-design.md §14.2):
        // only the account — or the session token — that the cart was claimed by
        // may learn which order it produced. Every other cart_id, claimed or live,
        // is simply not found, exactly like an unknown id.
        if ($this->claimFor($input) !== null) {
            return $this->replayFor($input);
        }

        try {
            $result = DB::transaction(fn () => $this->placeWithinTransaction($input, $placedAt));
        } catch (CartClaimLostException) {
            // A concurrent request claimed this cart between the fast
            // path above and this attempt's own claim (step 10) —
            // resolve idempotently, same as the fast path.
            return $this->replayFor($input);
        } catch (CartNotFoundForCheckoutException $exception) {
            // The cart VANISHED between the fast path and this transaction's own
            // load, which can only mean it was claimed in that window: a claimed
            // cart is invisible to findById() by construction (§14.1). That is a
            // double-submit, not a missing cart, so it resolves exactly like a lost
            // claim — and a genuinely unknown id still 404s, as before.
            if ($this->claimFor($input) !== null) {
                return $this->replayFor($input);
            }

            throw $exception;
        }

        if ($result->isAlreadyPlaced()) {
            // NEVER re-charge on a replay — this is the whole point of
            // §6's idempotency. A double-clicked "Pay" button must not
            // produce a second charge attempt against the same order.
            return $result;
        }

        $order = $result->order();
        $payment = $result->payment();

        // Step 13 — outside the transaction, per checkout-orchestration-
        // performance-note.md §2: never hold a DB transaction open
        // across a call to an external system, even though V1's two
        // adapters are deterministic and offline. This shape must
        // already be correct for the day a real provider adapter
        // replaces them.
        //
        // $payment already exists as a real, committed PENDING row from
        // Phase 1 (see class docblock) — this call records THIS
        // attempt's actual outcome onto it, never creates a second row.
        // If charge() itself throws, or the process dies before the
        // save() below completes, $payment is left exactly as Phase 1
        // committed it: PENDING with a NULL attemptedAt — the real,
        // findable "attempt never completed" state this whole change
        // exists to produce, instead of no row at all.
        //
        // A FAILED PaymentAttemptResult is recorded as-is — the Order
        // stands, stock stays decremented, nothing is compensated. §8.3
        // step 13 already flags compensation for a real online
        // provider's synchronous failure as deliberately out of scope.
        // Both V1 adapters always return PENDING — a legitimate final
        // answer for an offline method, not an edge case — so
        // recordAttemptResult() is written to accept it, not reject it;
        // see Payment's own class docblock for why the "already
        // recorded" guard lives on attemptedAt rather than on status.
        $adapter = $this->adapterResolver->resolve($input->paymentMethod);
        $attempt = $adapter->charge($order->total(), new PaymentContext($order->id()));
        $payment->recordAttemptResult(
            status: $attempt->status(),
            providerReference: $attempt->providerReference(),
            failureReason: $attempt->failureReason(),
            attemptedAt: new DateTimeImmutable(),
        );
        $this->payments->save($payment);

        // Step 14 — the extension point extensibility-design-and-
        // hooks.md §1 already names. Fires regardless of payment outcome
        // — the event is 'order.placed', not 'order.paid': the order
        // genuinely was placed. A future listener that cares about money
        // should read the Payment, not assume this hook implies payment
        // succeeded.
        Hook::fire('order.placed', $order);

        return CheckoutResult::placed($order, $payment);
    }

    /** The claim on this request's cart, iff the requester is the identity it was claimed by. */
    private function claimFor(CheckoutInput $input): ?CartClaim
    {
        return $this->carts->findClaimForIdentity($input->cartId, $input->accountId, $input->guestCartToken);
    }

    /**
     * The idempotent answer for a replay: the order the claimed cart produced, or a
     * clean "no cart" when the claim is no longer answerable (the order row was
     * deleted, which NULLs carts.order_id — checkout-domain-design.md §6). Never
     * re-charges, never writes.
     */
    private function replayFor(CheckoutInput $input): CheckoutResult
    {
        $claim = $this->claimFor($input);

        if ($claim === null) {
            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        $order = $this->orders->findById($claim->orderId);

        if ($order === null) {
            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        return CheckoutResult::alreadyPlaced($order);
    }

    /**
     * Ownership of a LIVE cart: the account for an account cart, the session's own
     * token for a guest cart (cart-domain-design.md §8 — a client never supplies the
     * token itself, so holding the session is what makes the cart theirs). An id
     * alone is never enough to buy someone else's cart, and a cart that is not the
     * requester's is a 404, indistinguishable from an unknown id.
     */
    private function isOwnedByRequester(Cart $cart, CheckoutInput $input): bool
    {
        if ($cart->accountId() !== null) {
            return $input->accountId !== null && $cart->accountId() === $input->accountId;
        }

        return $input->guestCartToken !== null && $cart->sessionToken() === $input->guestCartToken;
    }

    private function placeWithinTransaction(CheckoutInput $input, DateTimeImmutable $placedAt): CheckoutResult
    {
        // Step 1: load the cart, reject empty.
        $cart = $this->carts->findById($input->cartId);

        if ($cart === null) {
            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        // ...and it must be THIS requester's cart (cart-domain-design.md §14.2).
        // Enforced here, in the one place every checkout goes through, so no caller
        // can buy — or learn anything about — a cart that is not its own.
        if (! $this->isOwnedByRequester($cart, $input)) {
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
        [$appliedPromotion, $discount, $perLineShares] = $this->resolvePromotion($cart, $subtotal, $pricingResults, $input->accountId);

        // Step 5: resolve the Address.
        $address = $this->resolveAddress($input);

        // Step 6: resolve the Client.
        $client = $this->clientResolver->resolve($input->accountId, $input->recipientName);

        // Step 7: decrease stock per line; InsufficientStockException
        // propagates uncaught, aborting the transaction.
        foreach ($cart->lines() as $line) {
            $this->stockLevels->decrease($line->variationId(), $line->quantity());
        }

        // Step 8: Transaction + one full-snapshot SALE SaleLine per line,
        // via the one app-layer builder (operational-sales-domain-
        // design.md §3.13 E-D4/D1) — Web Checkout always passes a zero
        // per-line discretionaryDiscount (E-D3: no discretionary-discount
        // UI exists on the storefront; the field itself is per-line, not
        // per-cart — see SaleLineSnapshotBuilder's own class docblock).
        $transaction = new Transaction(null, Channel::WEB);

        $builderLines = [];
        foreach ($pricingResults as $index => $result) {
            $builderLines[] = [
                'variationId' => $result->variationId(),
                'quantity' => $result->quantity(),
                'regularUnitPrice' => $result->regularUnitPrice(),
                'finalUnitPrice' => $result->unitPrice(),
                'unitCost' => $result->unitCost(),
                'productName' => $result->productName(),
                'sku' => $result->sku(),
                'promotionDiscountShare' => $perLineShares[$index],
                'discretionaryDiscount' => Money::zero($currency),
            ];
        }

        $saleLines = $this->saleLineSnapshotBuilder->buildForCart(
            lines: $builderLines,
            transactionId: '',
            clientId: $client->id(),
            status: SaleLineStatus::COMPLETED,
            recordedAt: $placedAt,
            effectiveAt: $placedAt,
        );

        // D6 — order-level reconciliation, checked as early as possible:
        // BEFORE either the Transaction or the Order is written, not
        // after. Order.total is always subtotal->subtract(discount)
        // exactly (Order::create()'s own docblock/computation) — computed
        // directly here rather than waiting for a real Order instance,
        // since nothing else about that computation depends on the Order
        // object itself. operational-sales-domain-design.md §3.13's own
        // Invariants section names exactly these two sums and states they
        // "should never actually fire in production" if the Promotion
        // allocation rule is implemented correctly — the same cheap,
        // always-on corruption-detector posture as SaleLine::create()'s
        // own formula checks, not routine defensive programming against
        // an expected failure. Holds only as long as checkout-domain-
        // design.md §10 still holds (Order.total has no shipping
        // component) — see that section's own note for what changes the
        // day shipping is added.
        $this->assertSaleLinesReconcileWithOrder($saleLines, $discount, $subtotal->subtract($discount));

        foreach ($saleLines as $saleLine) {
            $transaction->addSaleLine($saleLine);
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

        // Write the Payment row NOW, as PENDING with no attempt outcome
        // yet — see class docblock for why this moved into Phase 1. A
        // crash before the cart claim below rolls this back along with
        // everything else in the transaction, exactly like the Order
        // itself; that's the whole point of writing it here, before the
        // claim, rather than after.
        $payment = Payment::create(
            orderId: $order->id(),
            method: $input->paymentMethod,
            amount: $order->total(),
            status: PaymentStatus::PENDING,
        );
        $this->payments->save($payment);

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

        return CheckoutResult::placed($order, $payment);
    }

    /**
     * PromotionUsageContext assembly is delegated to
     * PromotionUsageContextAssembler — the same instance
     * CartController::resolvePromotion() calls, so the per-setting query
     * guards live in exactly one place, not two.
     *
     * D3 — RETURNS A PER-LINE SHARE FOR EVERY PRICED LINE, not just the
     * applicable ones: $applicableLines used to be rebuilt via
     * array_values(array_filter(...)), which lost each applicable line's
     * original index into $pricingResults — needed to map
     * PromotionDiscountResult::perLineShares() (itself positionally
     * aligned with whatever array $applicableLines was) back onto the
     * right line. array_filter() alone (no array_values()) keeps the
     * original keys, so $applicableLines' keys ARE $pricingResults'
     * original indices; zipping perLineShares() back onto those same
     * keys, then filling every non-applicable index with zero, produces
     * $perLineShares indexed 0..count($pricingResults)-1 exactly like
     * $pricingResults itself — no second allocation, no order desync.
     *
     * @param array<int, CheckoutLinePricingResult> $pricingResults
     * @return array{0: ?Promotion, 1: Money, 2: array<int, Money>} [appliedPromotion, discount, perLineShares]
     */
    private function resolvePromotion(
        Cart $cart,
        Money $subtotal,
        array $pricingResults,
        ?string $accountId,
    ): array {
        $code = $cart->appliedPromotionCode();

        if ($code === null) {
            $zeroShares = array_fill(0, count($pricingResults), Money::zero($subtotal->currency()));

            return [null, Money::zero($subtotal->currency()), $zeroShares];
        }

        $promotion = $this->promotions->findByCode($code);

        if ($promotion === null) {
            throw new PromotionNoLongerValidException($code, 'not_found');
        }

        $scopes = $this->promotionScopes->findByPromotionId($promotion->id());

        // Per-setting query guards live in PromotionUsageContextAssembler
        // — see its own class docblock for why a Promotion with none of
        // newCustomersOnly/usageLimitTotal/usageLimitPerCustomer costs
        // zero extra queries here, and for what a false/0 value on the
        // result can and can't be taken to mean.
        $usage = $this->usageContextAssembler->assemble($promotion, $accountId);

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
        $applicableLines = array_filter(
            $validatorLines,
            static fn (array $line) => isset($applicableIds[$line['variationId']])
        );

        $discountResult = $this->promotionDiscountCalculator->calculate($promotion, array_values($applicableLines));

        $sharesByOriginalIndex = array_combine(array_keys($applicableLines), $discountResult->perLineShares());

        $perLineShares = [];
        foreach ($validatorLines as $index => $line) {
            $perLineShares[$index] = $sharesByOriginalIndex[$index] ?? Money::zero($subtotal->currency());
        }

        return [$promotion, $discountResult->amount(), $perLineShares];
    }

    /**
     * D6 — see the call site's own comment for why this check exists and
     * when it's expected to actually fire.
     *
     * @param SaleLine[] $saleLines
     *
     * @throws SaleLineOrderReconciliationException
     */
    private function assertSaleLinesReconcileWithOrder(array $saleLines, Money $discount, Money $orderTotal): void
    {
        $currency = $discount->currency();
        $shareSum = Money::zero($currency);
        $netPaidSum = Money::zero($currency);

        foreach ($saleLines as $saleLine) {
            $shareSum = $shareSum->add($saleLine->promotionDiscountShare());
            $netPaidSum = $netPaidSum->add($saleLine->netPaidAmount());
        }

        if (! $shareSum->equals($discount)) {
            throw new SaleLineOrderReconciliationException(
                "Sum of SaleLine promotionDiscountShare ({$shareSum->minorValue()}) does not equal ".
                "the order's discount ({$discount->minorValue()})."
            );
        }

        if (! $netPaidSum->equals($orderTotal)) {
            throw new SaleLineOrderReconciliationException(
                "Sum of SaleLine netPaidAmount ({$netPaidSum->minorValue()}) does not equal ".
                "the order's total ({$orderTotal->minorValue()})."
            );
        }
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
