<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\SaleLineOrderReconciliationException;
use App\Services\Exceptions\StaleOrderEditException;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Promotion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE ONE PLACE AN ORDER IS EDITED BEFORE IT SHIPS — order-editing-design.md
 * §5 (the unit of work), §6 (money), §7 (promotions), §9 (events and hook).
 * One apply() call = one DB::transaction() = one edit revision.
 *
 * It follows OrderStatusChanger's own shape exactly: lock the order FIRST
 * (findByIdForUpdate), do every write from inside that one lock, write one
 * order_events row, and fire the hook only after the commit. It checks NO
 * permission — the same posture every other service here takes; ORDER_MANAGE
 * and ORDER_DISCOUNT are the admin action's job (stage 4).
 *
 * THE STEPS, IN ORDER (each refusal happens before the first write that
 * could not be rolled back — and everything is inside one transaction, so
 * even a late refusal rolls the whole edit back together):
 *
 *   1  revision compare-and-set (StaleOrderEditException)      — nothing else runs on a stale form
 *   2  status gate (OrderNotEditableException)                 — placed/confirmed only
 *   3  money gate (OrderNotEditableException::becausePaymentSettled) — any settled payment refuses the whole edit
 *   4  resolve the order's CURRENT lines (§4.4)
 *   5  plan the promotion over the lines the edit will produce, and
 *      resolve each resulting line's promotionDiscountShare
 *   6  OrderLineEditor::apply() — the ledger, stock (or nothing, for a delivery-only edit)
 *   7  totals recomputed FROM the resulting lines -> Order::reviseTotals()/reviseDelivery()/bumpEditRevision()
 *   8  redemption release / new redemption
 *   9  the payment: void-and-reissue a pending one for the new total
 *   10 one order_events(EDITED) row
 *
 * WHY THE PROMOTION IS PLANNED (5) BEFORE THE LINES ARE WRITTEN (6) — a
 * difference from the order the stage's brief lists them in: a SALE line's
 * promotionDiscountShare is fixed at construction and never rewritable
 * (operational-sales-domain-design.md §3.2/§3.13), and OrderLineEditor writes
 * lines with the shares it is handed. The shares depend on the resulting
 * line set (a fixed-amount code re-allocates across every applicable line; a
 * percentage over a changed base changes each changed line's share), so they
 * must be computed from the PROSPECTIVE resulting lines and passed in; there
 * is no way to write the lines first and fix the shares afterwards. The
 * prospective lines are derived from the very same change entries the editor
 * then writes, and the totals are then recomputed from what it actually wrote
 * (and reconciled against the promotion's own discount) so the two cannot
 * drift silently.
 *
 * A LINE WHOSE SHARE CHANGES IS REPLACED, NOT PATCHED: an untouched line
 * whose new share differs from its own is fully reversed and re-written by
 * the editor's existing "discount" change (its own §7 redistribution hook)
 * carrying its own unchanged discretionary discount. That is the ledger's
 * honest answer to an immutable line — never an in-place rewrite.
 *
 * TOTALS, THE SAME FORMULA CheckoutOrchestrator USES, EXTENDED FOR THE ONE
 * THING CHECKOUT NEVER HAS — a manual (discretionary) discount:
 * subtotal = sum of each line's amount (unit price x quantity, before any
 * discount); discount = sum of each line's promotionDiscountShare PLUS its
 * discretionaryDiscount; the GOODS total = subtotal - discount = sum of
 * netPaidAmount. The ORDER's total is that plus the order's stored shipping
 * (shipping-domain-design.md §7): an edit never re-prices shipping, so
 * Order::reviseTotals() adds the shipping it already holds, and the
 * pending-payment comparison and reissue use that resulting total. The
 * ledger reconciliation stays goods-only — shipping is not a SaleLine.
 * For a checkout order discretionary is zero on every line, so the goods
 * formula collapses exactly to CheckoutOrchestrator's own
 * `subtotal - promotionDiscount`, and CheckoutOrchestrator::
 * assertSaleLinesReconcileWithOrder()'s two sums (shares == discount,
 * netPaid == goods total) hold by construction here too.
 */
final class OrderEditor
{
    private const CHANGE_ADD = 'add';

    private const CHANGE_REMOVE = 'remove';

    private const CHANGE_QUANTITY = 'change_quantity';

    private const CHANGE_DISCOUNT = 'discount';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly OrderCurrentLinesResolver $currentLinesResolver,
        private readonly OrderLineEditor $lineEditor,
        private readonly OrderEventRecorder $events,
        private readonly PendingPaymentReissuer $paymentReissuer,
        private readonly PromotionRepository $promotions,
        private readonly PromotionScopeRepository $promotionScopes,
        private readonly PromotionRedemptionRepository $promotionRedemptions,
        private readonly PromotionRedeemer $promotionRedeemer,
        private readonly PromotionValidator $promotionValidator,
        private readonly PromotionDiscountCalculator $promotionDiscountCalculator,
        private readonly PromotionUsageContextAssembler $usageContextAssembler,
        private readonly CatalogScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lineChanges  OrderLineEditor::apply()'s own $changes shape, passed through — except that every promotionDiscountShare is OWNED BY THIS CLASS: the share on an "add" change's pricedLine, and on a "discount" change, is overwritten with the one this edit's promotion plan resolves. An empty list is a delivery/promotion-only edit.
     * @param  ?OrderDeliveryChange  $delivery  Null = the delivery snapshot is unchanged by this edit.
     *
     * @throws InvalidArgumentException Empty/unknown order id; a malformed or contradictory change; a legacy line with no §3.13 snapshot; an anomalous payment state.
     * @throws StaleOrderEditException If $expectedRevision is no longer the order's edit_revision.
     * @throws OrderNotEditableException If the status is not placed/confirmed, or a payment has already settled.
     * @throws PromotionNoLongerValidException If a code that is kept or newly set is not valid for the resulting lines — never silently dropped; the merchant removes it explicitly.
     * @throws \EasyCo\Inventory\Exceptions\InsufficientStockException If an added/increased unit cannot be reserved.
     */
    public function apply(
        string $orderId,
        int $expectedRevision,
        array $lineChanges,
        ?OrderDeliveryChange $delivery,
        OrderPromotionCodeChange $promotionCode,
        ?string $editedBy,
        ?string $editedByName,
        ?string $reason,
        DateTimeImmutable $occurredAt,
    ): void {
        if (trim($orderId) === '') {
            throw new InvalidArgumentException('OrderEditor: orderId must not be empty.');
        }

        $order = DB::transaction(fn (): Order => $this->applyWithinTransaction(
            $orderId,
            $expectedRevision,
            $lineChanges,
            $delivery,
            $promotionCode,
            $editedBy,
            $editedByName,
            $reason,
            $occurredAt,
        ));

        // AFTER the commit, and only because the edit really happened
        // (§9, order-lifecycle-design.md §11 item 9): a refused or rolled-
        // back edit never reaches this line.
        Hook::fire('order.edited', $order, $order->editRevision());
    }

    private function applyWithinTransaction(
        string $orderId,
        int $expectedRevision,
        array $lineChanges,
        ?OrderDeliveryChange $delivery,
        OrderPromotionCodeChange $promotionCode,
        ?string $editedBy,
        ?string $editedByName,
        ?string $reason,
        DateTimeImmutable $occurredAt,
    ): Order {
        $order = $this->orders->findByIdForUpdate($orderId);

        if ($order === null) {
            throw new InvalidArgumentException("OrderEditor: order \"{$orderId}\" does not exist.");
        }

        // 1. The stale-form check comes before every other guard (§5 step 3).
        if ($order->editRevision() !== $expectedRevision) {
            throw new StaleOrderEditException($orderId, $expectedRevision, $order->editRevision());
        }

        // 2. An explicit, early status gate. reviseTotals()/reviseDelivery()
        // would refuse the same fact later, but only AFTER lines and stock
        // had been written (all rolled back, but wasted) and — more
        // importantly — the refusal for a shipped order that also has a
        // settled payment would then depend on which guard happened to run
        // first. Kept so the refusal order is stated, not incidental.
        if (! in_array($order->status(), [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
            throw OrderNotEditableException::because($order->status());
        }

        // 3. E3's money gate: one settled payment refuses the WHOLE edit.
        $orderPayments = $this->payments->findByOrderId($orderId);

        foreach ($orderPayments as $payment) {
            if ($payment->isSettled()) {
                throw OrderNotEditableException::becausePaymentSettled($order->status());
            }
        }

        $pendingPayment = $this->paymentReissuer->currentPending($orderPayments, $orderId);

        // 4. The order's current lines.
        $currentLines = $this->currentLinesResolver->resolve($order);

        // 5. The promotion plan, and every resulting line's share.
        $plan = $this->planPromotion($order, $currentLines, $lineChanges, $promotionCode);

        // 6. The ledger and stock — skipped only when the edit changes no
        // line at all (a delivery-only edit, or a kept code with nothing to
        // recompute), where there is nothing to write and no Transaction to
        // point the event at.
        $editTransactionId = null;
        $resultingLines = $currentLines;

        if ($plan['changes'] !== []) {
            $result = $this->lineEditor->apply(
                currentLines: $currentLines,
                changes: $plan['changes'],
                clientId: $order->clientId(),
                occurredAt: $occurredAt,
                editedBy: $editedBy,
                editedByName: $editedByName,
                reason: $reason,
            );

            $editTransactionId = $result->transaction()->id();
            $resultingLines = $result->resultingLines();
        }

        // 7. Totals from what was actually written.
        [$subtotal, $discount, $goodsTotal, $shareSum] = $this->totalsFrom($order, $resultingLines);

        if ($plan['promotionDiscount'] !== null && ! $shareSum->equals($plan['promotionDiscount'])) {
            throw new SaleLineOrderReconciliationException(
                "Sum of resulting SaleLine promotionDiscountShare ({$shareSum->minorValue()}) does not equal ".
                "the promotion's own discount ({$plan['promotionDiscount']->minorValue()})."
            );
        }

        // The ORDER's total is the goods total plus the stored shipping, which
        // reviseTotals() adds itself; $goodsTotal (sum of netPaid) stays the
        // ledger's number and was already reconciled inside totalsFrom().
        $order->reviseTotals($subtotal, $discount, $plan['appliedCode']);
        $total = $order->total();

        if ($delivery !== null) {
            $order->reviseDelivery(
                deliveryType: $delivery->deliveryType,
                recipientName: $delivery->recipientName,
                phone: $delivery->phone,
                country: $delivery->country,
                city: $delivery->city,
                postalCode: $delivery->postalCode,
                addressLine1: $delivery->addressLine1,
                addressLine2: $delivery->addressLine2,
                carrierCode: $delivery->carrierCode,
                pickupPointReference: $delivery->pickupPointReference,
                settlement: $delivery->settlement,
            );
        }

        // Only after both revise*() calls have succeeded (bumpEditRevision()'s
        // own documented ordering contract).
        $order->bumpEditRevision();

        $this->orders->save($order);

        // 8. Promotion redemption bookkeeping.
        if ($plan['releaseRedemption']) {
            $redemption = $this->promotionRedemptions->findByOrderId($orderId);

            if ($redemption !== null && ! $redemption->isReleased()) {
                $redemption->release($occurredAt);
                $this->promotionRedemptions->save($redemption);
            }
        }

        if ($plan['redeem'] !== null) {
            $this->promotionRedeemer->redeemAtomically($plan['redeem'], $orderId, $order->accountId(), $occurredAt);
        }

        // 9. THE MONEY CONSEQUENCE (§6). Compared against the pending
        // payment's OWN amount — which equals the order's previous total
        // whenever the two agree, as every writer here keeps them — so a
        // same-total edit touches no payment and a payment that had somehow
        // drifted from the order is corrected rather than left stale.
        if ($pendingPayment !== null && ! $pendingPayment->amount()->equals($total)) {
            if ($total->isPositive()) {
                $this->paymentReissuer->reissueFor($pendingPayment, $total, $occurredAt);
            } else {
                $this->paymentReissuer->voidOnly($pendingPayment, $occurredAt);
            }
        }

        // 10. One event, both statuses null (an edit is not a transition).
        $this->events->record(
            orderId: $orderId,
            type: OrderEventType::EDITED,
            fromStatus: null,
            toStatus: null,
            reason: $reason,
            transactionId: $editTransactionId,
            occurredAt: $occurredAt,
        );

        return $order;
    }

    /**
     * Resolves the promotion state and, with it, every resulting line's
     * share; returns the change entries to hand OrderLineEditor (with every
     * share this class owns written into them) and the promotion facts the
     * later steps need. See the class docblock for why this precedes the
     * write.
     *
     * @param  array<int, SaleLine>  $currentLines
     * @param  array<int, array<string, mixed>>  $lineChanges
     * @return array{changes: array<int, array<string, mixed>>, appliedCode: ?string, promotionDiscount: ?Money, releaseRedemption: bool, redeem: ?Promotion}
     */
    private function planPromotion(Order $order, array $currentLines, array $lineChanges, OrderPromotionCodeChange $codeChange): array
    {
        $applied = $order->appliedPromotionCode();
        $newPromotion = null;

        if ($codeChange->isSet()) {
            $newPromotion = $this->promotions->findByCode($codeChange->code())
                ?? throw new PromotionNoLongerValidException($codeChange->code(), 'not_found');

            // "Set" to the code the order already carries is not a new use of
            // it: treated as unchanged, so it neither releases nor
            // re-redeems. Compared against the RESOLVED promotion's own code
            // (codes are stored normalised), never the raw string typed.
            if ($applied !== null && $newPromotion->code() === $applied) {
                $codeChange = OrderPromotionCodeChange::unchanged();
                $newPromotion = null;
            }
        }

        // A kept code over lines this edit does not touch: nothing about
        // the lines changed, so neither can the discount — and re-validating
        // would only let the passage of time (an expiry since placement)
        // block an unrelated delivery edit. Every line already carries its
        // own share; nothing is recomputed and nothing is written here.
        if ($codeChange->isUnchanged() && $lineChanges === []) {
            return ['changes' => [], 'appliedCode' => $applied, 'promotionDiscount' => null, 'releaseRedemption' => false, 'redeem' => null];
        }

        $prospective = $this->prospectiveLines($currentLines, $lineChanges);
        $zero = Money::zero($order->currency());

        $promotion = null;
        $usage = null;
        $release = false;
        $redeem = null;

        if ($codeChange->isRemoved()) {
            $release = $applied !== null;
        } elseif ($codeChange->isSet()) {
            $promotion = $newPromotion;
            $release = $applied !== null;
            $redeem = $promotion;
            // This order itself must not count as the customer's "previous
            // order" for a new-customers-only code.
            $usage = $this->usageContextAssembler->assemble($promotion, $order->accountId(), $order->id());
        } elseif ($applied !== null) {
            $promotion = $this->promotions->findByCode($applied)
                ?? throw new PromotionNoLongerValidException($applied, 'not_found');
            // The code was already validly redeemed by THIS order at
            // placement, so its usage limits and new-customers rule were
            // checked then and this order's own redemption is what a
            // recount would now see: a neutral context answers "is it still
            // valid for these lines" without the order counting against
            // itself.
            $usage = new PromotionUsageContext(false, 0, 0);
        }

        $shares = array_fill(0, count($prospective), $zero);
        $promotionDiscount = $zero;

        if ($promotion !== null) {
            $scopes = $this->promotionScopes->findByPromotionId($promotion->id());

            $validatorLines = array_map(static fn (array $line): array => [
                'variationId' => $line['variationId'],
                'quantity' => $line['quantity'],
                'unitPrice' => $line['unitPrice'],
                'lineTotal' => $line['lineTotal'],
                'productId' => $line['productId'],
                'matchingScopeReferenceIds' => $line['matchingScopeReferenceIds'],
                'isDiscounted' => $line['isDiscounted'],
            ], $prospective);

            $subtotal = $zero;
            foreach ($validatorLines as $line) {
                $subtotal = $subtotal->add($line['lineTotal']);
            }

            $validation = $this->promotionValidator->validate($promotion, $scopes, $subtotal, $order->accountId(), $validatorLines, $usage);

            if (! $validation->isValid()) {
                // Refused, never dropped: the merchant asked for (or kept)
                // this code and must remove it explicitly to proceed.
                throw new PromotionNoLongerValidException($promotion->code(), $validation->reason());
            }

            $applicableIds = array_flip($validation->applicableVariationIds());
            $applicableLines = array_filter(
                $validatorLines,
                static fn (array $line): bool => isset($applicableIds[$line['variationId']])
            );

            $discountResult = $this->promotionDiscountCalculator->calculate($promotion, array_values($applicableLines));
            $sharesByIndex = array_combine(array_keys($applicableLines), $discountResult->perLineShares());

            foreach (array_keys($validatorLines) as $index) {
                $shares[$index] = $sharesByIndex[$index] ?? $zero;
            }

            $promotionDiscount = $discountResult->amount();
        }

        return [
            'changes' => $this->withShares($lineChanges, $currentLines, $prospective, $shares),
            'appliedCode' => $promotion?->code(),
            'promotionDiscount' => $promotionDiscount,
            'releaseRedemption' => $release,
            'redeem' => $redeem,
        ];
    }

    /**
     * The lines the edit WILL produce, in the order OrderLineEditor's own
     * resultingLines() returns them (a replaced line keeps its position,
     * added lines follow in the order named), with the per-line facts
     * PromotionValidator needs. Scope facts come from one batched
     * CatalogScopeResolver::forVariations() call. isDiscounted is read off
     * the line's OWN price snapshot (regular above final) — the only honest
     * source for a line already sold, and the same fact the live quote's
     * isDiscounted() answered at checkout.
     *
     * A malformed change entry is refused HERE, with a plain
     * InvalidArgumentException, because this method has to read the entries
     * before OrderLineEditor ever sees them; the editor stays the
     * authoritative validator of everything else.
     *
     * @param  array<int, SaleLine>  $currentLines
     * @param  array<int, array<string, mixed>>  $lineChanges
     * @return array<int, array{originId: ?string, variationId: string, quantity: int, unitPrice: Money, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool}>
     */
    private function prospectiveLines(array $currentLines, array $lineChanges): array
    {
        $byId = [];
        foreach ($currentLines as $line) {
            $byId[(string) $line->id()] = $line;
        }

        $removed = [];
        $quantities = [];
        $adds = [];

        foreach ($lineChanges as $index => $change) {
            if (! is_array($change) || ! isset($change['change']) || ! in_array($change['change'], [self::CHANGE_ADD, self::CHANGE_REMOVE, self::CHANGE_QUANTITY, self::CHANGE_DISCOUNT], true)) {
                throw new InvalidArgumentException("OrderEditor: lineChanges[{$index}] is not a change entry with a known \"change\" kind.");
            }

            if ($change['change'] === self::CHANGE_ADD) {
                $priced = $change['pricedLine'] ?? null;

                if (! is_array($priced) || ! is_string($priced['variationId'] ?? null) || ! is_int($priced['quantity'] ?? null)
                    || ! ($priced['finalUnitPrice'] ?? null) instanceof Money || ! ($priced['regularUnitPrice'] ?? null) instanceof Money) {
                    throw new InvalidArgumentException("OrderEditor: lineChanges[{$index}] is an \"add\" without a usable pricedLine.");
                }

                $adds[] = $priced;

                continue;
            }

            $origin = $change['originatingLine'] ?? null;

            if (! $origin instanceof SaleLine || ! isset($byId[(string) $origin->id()])) {
                throw new InvalidArgumentException("OrderEditor: lineChanges[{$index}] names a line that is not one of the order's current lines.");
            }

            $originId = (string) $origin->id();

            if ($change['change'] === self::CHANGE_REMOVE) {
                $removed[$originId] = true;
            } elseif ($change['change'] === self::CHANGE_QUANTITY) {
                if (! is_int($change['quantity'] ?? null) || $change['quantity'] < 1) {
                    throw new InvalidArgumentException("OrderEditor: lineChanges[{$index}] needs an integer quantity of at least 1.");
                }

                $quantities[$originId] = $change['quantity'];
            }
        }

        $entries = [];

        foreach ($currentLines as $line) {
            $id = (string) $line->id();

            if (isset($removed[$id])) {
                continue;
            }

            $entries[] = [
                'originId' => $id,
                'variationId' => (string) $line->priceableId(),
                'quantity' => $quantities[$id] ?? $line->quantity(),
                'unitPrice' => $line->finalUnitPrice(),
                'isDiscounted' => $line->regularUnitPrice()->subtract($line->finalUnitPrice())->isPositive(),
            ];
        }

        foreach ($adds as $priced) {
            $entries[] = [
                'originId' => null,
                'variationId' => $priced['variationId'],
                'quantity' => $priced['quantity'],
                'unitPrice' => $priced['finalUnitPrice'],
                'isDiscounted' => $priced['regularUnitPrice']->subtract($priced['finalUnitPrice'])->isPositive(),
            ];
        }

        $scopes = $this->scopeResolver->forVariations(array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['variationId'],
            $entries,
        ))));

        return array_map(static function (array $entry) use ($scopes): array {
            $scope = $scopes[$entry['variationId']] ?? ['productId' => null, 'matchingScopeReferenceIds' => []];

            return $entry + [
                'lineTotal' => $entry['unitPrice']->multiply($entry['quantity']),
                'productId' => $scope['productId'],
                'matchingScopeReferenceIds' => $scope['matchingScopeReferenceIds'],
            ];
        }, $entries);
    }

    /**
     * Writes every resolved share into the change list handed to
     * OrderLineEditor: onto each "add"'s pricedLine, onto each existing
     * "discount" entry, as a companion "discount" entry for a line whose
     * quantity changes (the editor merges it with the quantity change into
     * ONE reversal + ONE replacement), and as a fresh "discount" entry for an
     * otherwise-untouched line whose share moved. A companion/fresh entry
     * carries the line's OWN discretionary discount unchanged. A line whose
     * share is already right and that no change names is left alone — no
     * churn in the ledger.
     *
     * @param  array<int, array<string, mixed>>  $lineChanges
     * @param  array<int, SaleLine>  $currentLines
     * @param  array<int, array<string, mixed>>  $prospective  prospectiveLines()'s result, in resulting-line order.
     * @param  array<int, Money>  $shares  Same indexing as $prospective.
     * @return array<int, array<string, mixed>>
     */
    private function withShares(array $lineChanges, array $currentLines, array $prospective, array $shares): array
    {
        $changes = array_values($lineChanges);
        $shareByOrigin = [];
        $addShares = [];

        foreach ($prospective as $index => $entry) {
            if ($entry['originId'] === null) {
                $addShares[] = $shares[$index];
            } else {
                $shareByOrigin[$entry['originId']] = $shares[$index];
            }
        }

        $addPosition = 0;
        $hasDiscountEntry = [];

        foreach ($changes as $position => $change) {
            if ($change['change'] === self::CHANGE_ADD) {
                $changes[$position]['pricedLine']['promotionDiscountShare'] = $addShares[$addPosition++];

                continue;
            }

            if ($change['change'] === self::CHANGE_DISCOUNT) {
                $originId = (string) $change['originatingLine']->id();
                $hasDiscountEntry[$originId] = true;
                $changes[$position]['promotionDiscountShare'] = $shareByOrigin[$originId] ?? $change['originatingLine']->promotionDiscountShare();
            }
        }

        // Every surviving line no "discount" entry already covers: a
        // quantity-changed line gets a companion entry so its replacement
        // carries the new share, and an untouched line whose share moved is
        // replaced too; a line whose share is already right is left alone.
        foreach ($currentLines as $line) {
            $originId = (string) $line->id();

            if (isset($hasDiscountEntry[$originId]) || ! isset($shareByOrigin[$originId])) {
                continue;
            }

            $share = $shareByOrigin[$originId];

            if ($share->equals($line->promotionDiscountShare())) {
                continue;
            }

            $changes[] = [
                'change' => self::CHANGE_DISCOUNT,
                'originatingLine' => $line,
                'discretionaryDiscount' => $line->discretionaryDiscount(),
                'promotionDiscountShare' => $share,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<int, SaleLine>  $lines  The lines totals are summed over.
     * @return array{0: Money, 1: Money, 2: Money, 3: Money} [subtotal, discount, GOODS total (sum of netPaid, no shipping), promotion-share sum]
     */
    private function totalsFrom(Order $order, array $lines): array
    {
        $zero = Money::zero($order->currency());
        $subtotal = $zero;
        $shareSum = $zero;
        $discretionarySum = $zero;
        $total = $zero;

        foreach ($lines as $line) {
            $share = $line->promotionDiscountShare();
            $discretionary = $line->discretionaryDiscount();
            $net = $line->netPaidAmount();

            if ($share === null || $discretionary === null || $net === null) {
                throw new InvalidArgumentException("OrderEditor: a resulting line of order \"{$order->id()}\" carries no §3.13 snapshot.");
            }

            $subtotal = $subtotal->add($line->amount());
            $shareSum = $shareSum->add($share);
            $discretionarySum = $discretionarySum->add($discretionary);
            $total = $total->add($net);
        }

        $discount = $shareSum->add($discretionarySum);

        if (! $subtotal->subtract($discount)->equals($total)) {
            throw new SaleLineOrderReconciliationException(
                "Recomputed subtotal ({$subtotal->minorValue()}) minus discount ({$discount->minorValue()}) does not equal ".
                "the sum of the lines' netPaidAmount ({$total->minorValue()})."
            );
        }

        return [$subtotal, $discount, $total, $shareSum];
    }
}
