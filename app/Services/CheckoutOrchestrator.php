<?php

namespace App\Services;

use App\Services\Exceptions\CartClaimLostException;
use App\Services\Exceptions\CartNotFoundForCheckoutException;
use App\Services\Exceptions\CheckoutCurrencyMismatchException;
use App\Services\Exceptions\EmptyCartException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\SaleLineOrderReconciliationException;
use App\Services\Exceptions\ShippingQuoteRefusedException;
use App\Services\Exceptions\ZeroTotalException;
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
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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
        private readonly CartPricing $cartPricing,
        private readonly PromotionRedeemer $promotionRedeemer,
        private readonly OrderRepository $orders,
        private readonly ClientResolver $clientResolver,
        private readonly AddressResolver $addressResolver,
        private readonly StockLevelRepository $stockLevels,
        private readonly TransactionRepository $transactions,
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodAdapterResolver $adapterResolver,
        private readonly SaleLineSnapshotBuilder $saleLineSnapshotBuilder,
        private readonly CheckoutShippingResolver $shippingResolver,
        private readonly SettlementNormalizerResolver $normalizers,
        private readonly OrderPlacementSnapshotWriter $snapshotWriter,
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
     * @throws ShippingRefusal The shipping choice was refused (stage 4e): thrown before the transaction opens, nothing written, the cart untouched.
     * @throws \App\Services\Exceptions\ZeroTotalException The ORDER total (goods after discount + shipping) is not positive; nothing written (stage 4c, 4e).
     * @throws \App\Services\Exceptions\CheckoutCurrencyMismatchException A price in another currency; nothing written (stage 4c).
     * @throws SaleLineOrderReconciliationException Aborts Phase 1; nothing written.
     *
     * Phase 2 (the payment attempt and the `order.placed` hook) NEVER throws (stage 4c): a failure there is logged and
     * reported through CheckoutResult::paymentNeedsAttention(), the committed order standing. That includes an unknown
     * payment method, which is only discovered there if the caller skipped the controller's pre-check.
     * @throws \EasyCo\Pricing\Exceptions\PriceNotConfiguredException Propagates uncaught — aborts the transaction (§8.3 step 3).
     * @throws \EasyCo\Inventory\Exceptions\InsufficientStockException Propagates uncaught — aborts the transaction (§8.3 step 7).
     * @throws \App\Services\Exceptions\AddressNotFoundForCheckoutException Propagates uncaught from AddressResolver::resolveExisting().
     * @throws \App\Services\Exceptions\AddressIncompleteForCheckoutException A saved address with no country; thrown before Phase 1, nothing written.
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

        // BEFORE Phase 1: a saved address that cannot make an order (no country,
        // a pre-D1 pickup point) is refused while nothing has been written. The
        // same resolution runs again inside the transaction; it is one cheap read.
        $savedAddress = null;

        if ($input->addressId !== null && $input->accountId !== null) {
            $savedAddress = $this->addressResolver->resolveExisting($input->addressId, $input->accountId);
        }

        // STEP 0 (shipping stage 4e) — BEFORE the transaction, so no lock is held while the quote pipeline runs
        // (it re-prices the cart and may ask a carrier). The cart is read here only to price its shipping; the
        // transaction below loads it again and is the authority on everything it writes. A refusal (ShippingRefusal)
        // leaves nothing written and the cart untouched. A replay never reaches this point: it returned above.
        $cart = $this->carts->findById($input->cartId);

        if ($cart === null) {
            // Vanished since the fast path: claimed in that window (a double submit) or genuinely unknown.
            if ($this->claimFor($input) !== null) {
                return $this->replayFor($input);
            }

            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        if (! $this->isOwnedByRequester($cart, $input)) {
            throw new CartNotFoundForCheckoutException($input->cartId);
        }

        if ($cart->isEmpty()) {
            throw new EmptyCartException("Cart \"{$input->cartId}\" has no lines to check out.");
        }

        $destination = $this->destinationFor($input, $savedAddress);
        try {
            $selection = $this->shippingResolver->resolve(
                $cart,
                $input->accountId,
                $destination,
                $input->shippingMethodId,
                $input->quoteHandle,
                $input->expectedShippingMinor,
            );
        } catch (ShippingQuoteRefusedException $e) {
            // The quote pipeline prices the cart in its lenient mode, so a cart in which EVERY line is unpriced is refused
            // there before the transaction's strict pricing can say so. Checkout's answer for it has always been the
            // unpriced-line refusal (409 price_not_available): keep it. The reason is the only thing read from $e.
            if ($e->reason === ShippingQuoteRefusedException::NO_PRICED_LINES) {
                throw PriceNotConfiguredException::forPriceableId($cart->lines()[0]->variationId());
            }

            if ($e->reason === ShippingQuoteRefusedException::EMPTY_CART) {
                throw new EmptyCartException('The cart has no lines to check out.');
            }

            throw $e;
        } catch (InvalidArgumentException $e) {
            // The quote pipeline prices the cart too, so a price held only in another currency surfaces here before
            // the transaction does: the same controlled refusal (see the pricing call in placeWithinTransaction()).
            throw $this->asCurrencyMismatch($e);
        }

        try {
            $result = DB::transaction(fn () => $this->placeWithinTransaction($input, $placedAt, $selection, $destination));
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
        //
        // CONTAINED (shipping stage 4c, §9.1.5, O5): the order is COMMITTED here, so nothing
        // thrown by the payment step may escape as a 500 for an order that exists. A Throwable
        // is logged (order id, payment id, exception class — never the message, which a
        // provider could fill with card or customer data), the Payment is left exactly as
        // Phase 1 committed it (PENDING, no attempt date: no status is invented), and the
        // result tells the controller the payment step needs attention.
        $paymentNeedsAttention = false;

        try {
            $adapter = $this->adapterResolver->resolve($input->paymentMethod);
            $attempt = $adapter->charge($order->total(), new PaymentContext($order->id()));
            $payment->recordAttemptResult(
                status: $attempt->status(),
                providerReference: $attempt->providerReference(),
                failureReason: $attempt->failureReason(),
                attemptedAt: new DateTimeImmutable(),
            );
            $this->payments->save($payment);
        } catch (Throwable $e) {
            Log::error('checkout.payment_step_failed', [
                'order_id' => $order->id(),
                'payment_id' => $payment->id(),
                'exception' => $e::class,
            ]);

            $paymentNeedsAttention = true;
            // The in-memory row may already carry an answer the database never received:
            // report what the database holds.
            $payment = $this->storedPayment($order->id());
        }

        // Step 14 — the extension point extensibility-design-and-
        // hooks.md §1 already names. Fires regardless of payment outcome
        // — the event is 'order.placed', not 'order.paid': the order
        // genuinely was placed. A future listener that cares about money
        // should read the Payment, not assume this hook implies payment
        // succeeded.
        //
        // CONTAINED (stage 4c, O6): the Hook registry never catches a listener's exception
        // (HookRegistry::doAction) and that rule stays. THIS call site is where a listener
        // may no longer turn a placed order into a 500: it is logged (order id and the
        // exception class only) and the checkout carries on, the payment result untouched.
        try {
            Hook::fire('order.placed', $order);
        } catch (Throwable $e) {
            Log::error('checkout.order_placed_listener_failed', [
                'order_id' => $order->id(),
                'exception' => $e::class,
            ]);
        }

        return CheckoutResult::placed($order, $payment, $paymentNeedsAttention);
    }

    /**
     * A price held only in another currency is not "no price": the resolver returns it and Money refuses to add it to the
     * store's currency (pinned in CartCheckoutPricingCharacterizationTest). Money's exception is a plain
     * InvalidArgumentException with no type of its own (Pricing is not touched by these stages), so it is recognised by the
     * start of its message; anything else is returned unchanged to be thrown as it was.
     */
    private function asCurrencyMismatch(InvalidArgumentException $e): Throwable
    {
        return str_starts_with($e->getMessage(), 'Currency mismatch') ? new CheckoutCurrencyMismatchException($e) : $e;
    }

    /**
     * Where this order is going, built by the same QuoteDestination builder the quote endpoint uses: a saved address from
     * its stored facts, a typed one from the checkout fields (a street address's city, a pickup point's settlement).
     */
    private function destinationFor(CheckoutInput $input, ?Address $savedAddress): QuoteDestination
    {
        if ($savedAddress !== null) {
            return QuoteDestination::fromAddress($savedAddress);
        }

        if ($input->addressId !== null) {
            // Impossible per §8.4 (guests have no saved addresses); resolveAddress() refuses it loudly in the transaction.
            throw new InvalidArgumentException('CheckoutInput::$addressId requires a non-null accountId; guests have no saved addresses.');
        }

        if ($input->deliveryType === null) {
            throw new InvalidArgumentException('CheckoutInput::$deliveryType is required when $addressId is null.');
        }

        $pickup = $input->deliveryType === \EasyCo\Address\Enums\AddressDeliveryType::PICKUP_POINT;

        return QuoteDestination::forAddress($input->deliveryType, (string) $input->country, $pickup ? $input->settlement : $input->city, $input->postalCode);
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

        return CheckoutResult::alreadyPlaced($order, $this->storedPayment($order->id()));
    }

    /**
     * The order's payment row as the database holds it (a read only), or null when there is none or the read fails.
     * V1 has one payment per order; the first is the one.
     */
    private function storedPayment(string $orderId): ?Payment
    {
        try {
            return $this->payments->findByOrderId($orderId)[0] ?? null;
        } catch (Throwable) {
            return null;
        }
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

    private function placeWithinTransaction(CheckoutInput $input, DateTimeImmutable $placedAt, ShippingSelection $selection, QuoteDestination $destination): CheckoutResult
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

        // Steps 2-4: price every line live and live-revalidate the applied promotion, via
        // the ONE shared calculation the cart preview also uses (CartPricing, stage 3.0c).
        // Checkout's two decisions are explicit here: an unpriced line is REFUSED
        // (PriceNotConfiguredException propagates, aborting the transaction), and a
        // promotion that is no longer valid is turned into PromotionNoLongerValidException.
        $currency = DefaultCurrency::get()->code();
        try {
            $pricing = $this->cartPricing->price($cart, $input->accountId, $currency, UnpricedLines::REFUSE, includeUnitCost: true);
        } catch (InvalidArgumentException $e) {
            throw $this->asCurrencyMismatch($e);
        }

        if ($pricing->promotionRefusal() !== null) {
            throw new PromotionNoLongerValidException((string) $pricing->appliedPromotionCode(), $pricing->promotionRefusal());
        }

        // The goods are priced here for real. If they are not the goods the shipping was resolved against (a line, a
        // price or the promotion moved since step 0), the shipping price may be wrong: refuse with the existing, retryable
        // "your cart changed" answer (409 checkout_state_changed) before anything is written. Only local facts are
        // re-derived — the zone and the destination are the ones step 0 matched.
        $hash = ShippingQuoteService::pricingHashFor(
            $destination,
            $this->normalizers->forCurrentLocale()->normalize((string) $destination->settlement),
            $selection->zoneId,
            $pricing->goodsAfterDiscount()->minorValue(),
            $currency,
            $cart,
        );

        if (! hash_equals($selection->pricingHash, $hash)) {
            throw new SaleLineOrderReconciliationException('The cart changed between resolving the shipping and placing the order.');
        }

        // Nothing to pay: a Payment cannot be for zero, so refuse BEFORE anything is written (stage 4c, O8). Judged on
        // the ORDER total — goods after discount plus shipping (stage 4e): free goods with a paid delivery is a payable order.
        $shipping = $selection->amount;

        if (! $pricing->goodsAfterDiscount()->add($shipping)->isPositive()) {
            throw new ZeroTotalException('The order total is not positive.');
        }

        $pricingResults = $pricing->pricedLines();
        $subtotal = $pricing->subtotal();
        $appliedPromotion = $pricing->promotion();
        $discount = $pricing->discount();
        $perLineShares = $pricing->perLineShares();

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
        $this->assertSaleLinesReconcileWithOrder($saleLines, $discount, $pricing->goodsAfterDiscount());

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
            pickupPointName: $address->pickupPointName(),
            pickupPointAddress: $address->pickupPointAddress(),
            shipping: $shipping,
            shippingMethodName: $selection->methodName,
            shippingMethodCode: $selection->methodId,
            shippingCourier: $selection->courier,
            shippingDeliveryType: $selection->deliveryType,
            shippingServiceCode: $selection->serviceCode,
        );

        $this->orders->save($order);

        // order-editing-design.md §2.1 — one write-once snapshot row, a
        // pure, mechanical copy of the Order row just saved above. Inside
        // this same placement transaction, so a rollback anywhere else in
        // Phase 1 takes this row with it, exactly like the Order itself.
        $this->snapshotWriter->write($order, $placedAt);

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
            $this->promotionRedeemer->redeemAtomically($appliedPromotion, $order->id(), $input->accountId, $placedAt);
        }

        return CheckoutResult::placed($order, $payment);
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
            pickupPointName: $input->pickupPointName,
            pickupPointAddress: $input->pickupPointAddress,
        );
    }
}
