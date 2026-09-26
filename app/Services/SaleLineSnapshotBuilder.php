<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Pricing\Money;
use LogicException;

/**
 * The ONE app-layer service that turns a priced cart/ticket line into a
 * full-snapshot SALE SaleLine, via SaleLine::create() —
 * operational-sales-domain-design.md §3.13 E-D4/D1. Web Checkout
 * (CheckoutOrchestrator) is its first caller; a future POS flow is its
 * second, with no change needed here — the input shape below is already
 * channel-agnostic (priced line data + promotion share + discretionary
 * discount, never Cart/CartLine/CheckoutInput knowledge).
 *
 * DISCRETIONARY DISCOUNT IS PER LINE, NOT PER CART — E-D3's own field is
 * a per-SaleLine fact (a POS cashier may knock a courtesy discount off
 * one specific line, not the whole ticket uniformly), so it lives on
 * each $lines entry, not as one cart-wide parameter. Web Checkout passes
 * Money::zero($currency) on every line (no discretionary-discount UI
 * exists on the storefront); a future POS caller passes whatever the
 * cashier actually applied, per line.
 *
 * D5 — SOLD ATTRIBUTES, BATCHED FOR THE WHOLE CART, NEVER PER LINE:
 * buildForCart() reads every line's Variation attribute assignments in
 * ONE VariationRepository::findByIds() call (itself ~2 bounded queries,
 * the same primitive CatalogScopeResolver::forVariations() already
 * reuses) plus one batched AttributeDefinitionModel::whereIn() and one
 * batched AttributeValueModel::whereIn() — 4 queries total regardless of
 * how many lines are in the cart, never one round trip per line. See
 * this class's own query-count test (SaleLineSnapshotBuilderQueryCountTest).
 *
 * NO SILENT FALLBACKS IN THAT READ — a variation id this class is asked
 * to snapshot but that findByIds() doesn't return, or an
 * attributeAssignments entry whose definition or value row is missing,
 * throws a LogicException naming the offending id, never silently
 * produces an empty/partial soldAttributes list. A SIMPLE product's
 * UNIVERSAL variation legitimately has zero assignments and still
 * yields [] — that is the correct, real answer for that case, not a
 * fallback; see batchLoadSoldAttributes()/soldAttributesFor() below for
 * exactly where each throw happens and why.
 *
 * D5's own axis-order finding, verified against the real installed
 * source before writing this class (the task's own §0 required this):
 * THERE IS NO AUTHORITATIVE VARIATION-AXIS ORDER ANYWHERE IN THIS
 * CODEBASE TODAY. catalog_product_attributes.sort_order exists as a
 * column (2026_08_23_000009's migration) but is ALWAYS written as the
 * literal 0 (EloquentProductRepository::persistVariationAxes(), both
 * call sites) — never a real per-axis position — and
 * EloquentProductRepository::loadVariationAxes() doesn't even ORDER BY
 * it; the order Product::variationAxes() returns is simply whatever
 * order MySQL happens to return catalog_product_attributes rows in for
 * that product_id, with no ORDER BY clause at all. Per this task's own
 * D5 instruction ("if there is none, use definition id and say so"):
 * soldAttributes below is sorted by attribute_definition_id ascending
 * (cast to int — these are real auto-increment ids), which is at least
 * DETERMINISTIC and STABLE across repeated reads of the same variation,
 * unlike trusting MySQL's unordered row-return order. If a merchant-
 * facing axis display order is ever designed (e.g. by finally wiring up
 * sort_order), this sort key is the one place to change.
 */
class SaleLineSnapshotBuilder
{
    public function __construct(
        private readonly VariationDisplayReader $displayReader,
    ) {
    }

    /**
     * @param array<int, array{variationId: string, quantity: int, regularUnitPrice: Money, finalUnitPrice: Money, unitCost: ?Money, productName: string, sku: string, promotionDiscountShare: Money, discretionaryDiscount: Money}> $lines
     * @return SaleLine[] Same count/order as $lines.
     */
    public function buildForCart(
        array $lines,
        string $transactionId,
        string $clientId,
        SaleLineStatus $status,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $effectiveAt,
    ): array {
        $variationIds = array_map(static fn (array $line): string => $line['variationId'], $lines);
        $soldAttributesByVariationId = $this->batchLoadSoldAttributes($variationIds);

        return array_map(function (array $line) use (
            $transactionId,
            $clientId,
            $status,
            $recordedAt,
            $effectiveAt,
            $soldAttributesByVariationId,
        ): SaleLine {
            $quantity = $line['quantity'];
            $finalUnitPrice = $line['finalUnitPrice'];
            $unitCost = $line['unitCost'];
            $discretionaryDiscount = $line['discretionaryDiscount'];

            $amount = $finalUnitPrice->multiply($quantity);
            $netPaidAmount = $amount
                ->subtract($line['promotionDiscountShare'])
                ->subtract($discretionaryDiscount);

            // D4 — profit on net: unitCost === null means genuinely
            // unknown (§3.13 Q2), not zero, so profit == netPaidAmount
            // exactly in that case rather than netPaidAmount - 0. This is
            // now the ONLY place profit is computed for a checkout SALE
            // line — CheckoutLinePricer/CheckoutLinePricingResult no
            // longer compute a (now-dead, pre-promotion) profit of their
            // own.
            $profit = $unitCost === null
                ? $netPaidAmount
                : $netPaidAmount->subtract($unitCost->multiply($quantity));

            // batchLoadSoldAttributes() above already guarantees every
            // requested variationId is present in this map (or throws) —
            // direct indexing, never `?? []`, per this class's own "no
            // silent fallbacks" rule.
            $soldAttributes = $soldAttributesByVariationId[$line['variationId']];

            return SaleLine::create(
                transactionId: $transactionId,
                clientId: $clientId,
                priceableId: $line['variationId'],
                status: $status,
                quantity: $quantity,
                amount: $amount,
                profit: $profit,
                recordedAt: $recordedAt,
                effectiveAt: $effectiveAt,
                productName: $line['productName'],
                sku: $line['sku'],
                regularUnitPrice: $line['regularUnitPrice'],
                finalUnitPrice: $finalUnitPrice,
                promotionDiscountShare: $line['promotionDiscountShare'],
                discretionaryDiscount: $discretionaryDiscount,
                netPaidAmount: $netPaidAmount,
                soldAttributes: $soldAttributes,
                unitCost: $unitCost,
            );
        }, $lines);
    }

    /**
     * @param string[] $variationIds
     * @return array<string, array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>> keyed by variationId — one entry for EVERY id in $variationIds, guaranteed by the throw below.
     *
     * @throws LogicException If findByIds() doesn't return one of the
     *   requested ids.
     */
    private function batchLoadSoldAttributes(array $variationIds): array
    {
        // DELEGATED, not duplicated: the cart API needs the same labels for its
        // own lines, so the batched walk lives in ONE place —
        // VariationDisplayReader — and this class calls it. The four queries it
        // costs and its throw-on-a-missing-variation-id behaviour are exactly what
        // this method used to do inline; SaleLineSnapshotBuilderQueryCountTest
        // proves the numbers are unchanged.
        return $this->displayReader->soldAttributesFor($variationIds);
    }
}
