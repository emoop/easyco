<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use InvalidArgumentException;

/**
 * Decides whether a Promotion currently applies to a given cart — see
 * promotions-domain-design.md §3's resolution rule and §4's Cart
 * contract. Pure logic only: no database calls of its own, no Cart
 * entity changes, no discount-amount computation (a later prompt, once
 * this validity/applicability logic is proven correct in isolation).
 * Every cross-domain fact it needs is passed in by the caller — same
 * "caller assembles the cross-domain data" posture as
 * CatalogScopeResolver, whose forVariation() return shape this
 * method's per-line productId/matchingScopeReferenceIds input mirrors
 * exactly.
 *
 * reason() codes returned by an invalid PromotionValidationResult:
 * - `inactive` — Promotion::isActive() is false.
 * - `not_yet_active` — now is before validFrom().
 * - `expired` — now is after validUntil().
 * - `minimum_spend_not_met` — cart subtotal is below minimumSpend().
 * - `maximum_spend_exceeded` — cart subtotal is above maximumSpend().
 * - `new_customers_only` — newCustomersOnly() is true and either the
 *   cart is a guest cart (accountId is null) or the account has
 *   $usage->customerHasPreviousOrders(). This method never queries
 *   OperationalSales/Order itself to determine that fact — per
 *   promotions-domain-design.md §2, the caller assembles it (via
 *   EasyCo\Order\Contracts\OrderRepository::hasAnyForAccount()) into
 *   PromotionUsageContext, the same "caller supplies the cross-domain
 *   facts" posture this whole class already keeps.
 * - `account_scope_mismatch` — an ACCOUNT-type scope's cart-level gate
 *   rejected this cart (see the class-level ACCOUNT-vs-per-line
 *   distinction below).
 * - `usage_limit_reached` — usageLimitTotal() is set and
 *   $usage->redemptionsTotal() has already reached it. A SOFT,
 *   non-locking check — the authoritative, locking check stays in
 *   Checkout (checkout-domain-design.md §7); this one exists so an
 *   already-exhausted code is rejected gracefully and early, the same
 *   as every other invalid-code case, rather than only failing deep
 *   inside the final checkout transaction.
 * - `usage_limit_per_customer_reached` — usageLimitPerCustomer() is set,
 *   the cart has a real accountId, and $usage->redemptionsForAccount()
 *   has already reached it. Same soft/non-locking posture as above. A
 *   guest is never rejected by this check — there is no reliable
 *   per-guest identity to count against, the identical reasoning §7 and
 *   new_customers_only already use for guests.
 * - `no_matching_lines` — every cart line was excluded (by scope
 *   mismatch or exclude_sale_items), leaving nothing for the discount
 *   to apply to.
 *
 * ACCOUNT SCOPE IS A CART-LEVEL GATE, NOT A PER-LINE FILTER — the one
 * real design nuance here, deliberately modeled as two distinct checks
 * rather than one unified per-line loop that happens to also handle
 * ACCOUNT. BRAND/CATEGORY/TAG/ATTRIBUTE_VALUE/PRODUCT scopes determine
 * which specific cart lines the discount touches; an ACCOUNT scope
 * instead determines whether the whole Promotion is usable by this
 * cart's customer AT ALL. A Promotion with an ACCOUNT INCLUDE scope
 * that doesn't match this cart's accountId is not "some lines don't
 * get the discount" — it's "this code isn't usable by this customer,"
 * full stop, checked once before any per-line resolution even begins.
 */
final class PromotionValidator
{
    /**
     * @param PromotionScope[] $scopes Every PromotionScope belonging to
     *   $promotion (i.e. the full result of
     *   PromotionScopeRepository::findByPromotionId()).
     * @param array<int, array{variationId: string, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool}> $cartLines
     */
    public function validate(
        Promotion $promotion,
        array $scopes,
        Money $cartSubtotal,
        ?string $accountId,
        array $cartLines,
        PromotionUsageContext $usage,
    ): PromotionValidationResult {
        if (! $promotion->isActive()) {
            return PromotionValidationResult::invalid('inactive');
        }

        $now = new DateTimeImmutable();

        if (! $promotion->isValidAt($now)) {
            if ($promotion->validFrom() !== null && $now < $promotion->validFrom()) {
                return PromotionValidationResult::invalid('not_yet_active');
            }

            return PromotionValidationResult::invalid('expired');
        }

        if ($promotion->minimumSpend() !== null) {
            $this->assertSameCurrency($cartSubtotal, $promotion->minimumSpend());

            if ($cartSubtotal->subtract($promotion->minimumSpend())->isNegative()) {
                return PromotionValidationResult::invalid('minimum_spend_not_met');
            }
        }

        if ($promotion->maximumSpend() !== null) {
            $this->assertSameCurrency($cartSubtotal, $promotion->maximumSpend());

            if ($cartSubtotal->subtract($promotion->maximumSpend())->isPositive()) {
                return PromotionValidationResult::invalid('maximum_spend_exceeded');
            }
        }

        if ($promotion->newCustomersOnly() && ($accountId === null || $usage->customerHasPreviousOrders())) {
            return PromotionValidationResult::invalid('new_customers_only');
        }

        if (! $this->accountScopeGatePasses($scopes, $accountId)) {
            return PromotionValidationResult::invalid('account_scope_mismatch');
        }

        if ($promotion->usageLimitTotal() !== null && $usage->redemptionsTotal() >= $promotion->usageLimitTotal()) {
            return PromotionValidationResult::invalid('usage_limit_reached');
        }

        if (
            $promotion->usageLimitPerCustomer() !== null
            && $accountId !== null
            && $usage->redemptionsForAccount() >= $promotion->usageLimitPerCustomer()
        ) {
            return PromotionValidationResult::invalid('usage_limit_per_customer_reached');
        }

        $applicableVariationIds = $this->applicableLines($promotion, $scopes, $cartLines);

        if ($applicableVariationIds === []) {
            return PromotionValidationResult::invalid('no_matching_lines');
        }

        return PromotionValidationResult::valid($applicableVariationIds);
    }

    /**
     * @param PromotionScope[] $scopes
     */
    private function accountScopeGatePasses(array $scopes, ?string $accountId): bool
    {
        $accountIncludeScopes = [];
        $accountExcludeScopes = [];

        foreach ($scopes as $scope) {
            if ($scope->scopeType() !== PromotionScopeType::ACCOUNT) {
                continue;
            }

            if ($scope->mode() === PromotionScopeMode::INCLUDE) {
                $accountIncludeScopes[] = $scope;
            } else {
                $accountExcludeScopes[] = $scope;
            }
        }

        if ($accountIncludeScopes !== []) {
            $matchesAnInclude = false;
            foreach ($accountIncludeScopes as $scope) {
                if ($accountId !== null && $scope->scopeReferenceId() === $accountId) {
                    $matchesAnInclude = true;

                    break;
                }
            }

            if (! $matchesAnInclude) {
                return false;
            }
        }

        foreach ($accountExcludeScopes as $scope) {
            if ($accountId !== null && $scope->scopeReferenceId() === $accountId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Per-line resolution for the five non-ACCOUNT scope types, per
     * §3's resolution rule: a line is applicable if (zero INCLUDE
     * scopes exist, or at least one INCLUDE scope matches that line)
     * AND (no EXCLUDE scope matches it) — then exclude_sale_items
     * removes any remaining line whose live price is currently
     * discounted, regardless of scope match.
     *
     * @param PromotionScope[] $scopes
     * @param array<int, array{variationId: string, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool}> $cartLines
     * @return string[]
     */
    private function applicableLines(Promotion $promotion, array $scopes, array $cartLines): array
    {
        $includeScopes = [];
        $excludeScopes = [];

        foreach ($scopes as $scope) {
            if ($scope->scopeType() === PromotionScopeType::ACCOUNT) {
                continue;
            }

            if ($scope->mode() === PromotionScopeMode::INCLUDE) {
                $includeScopes[] = $scope;
            } else {
                $excludeScopes[] = $scope;
            }
        }

        $applicable = [];

        foreach ($cartLines as $line) {
            if ($promotion->excludeSaleItems() && $line['isDiscounted']) {
                continue;
            }

            if ($includeScopes !== [] && ! $this->anyScopeMatchesLine($includeScopes, $line)) {
                continue;
            }

            if ($this->anyScopeMatchesLine($excludeScopes, $line)) {
                continue;
            }

            $applicable[] = $line['variationId'];
        }

        return $applicable;
    }

    /**
     * @param PromotionScope[] $scopes
     * @param array{variationId: string, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool} $line
     */
    private function anyScopeMatchesLine(array $scopes, array $line): bool
    {
        foreach ($scopes as $scope) {
            if ($this->scopeMatchesLine($scope, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{variationId: string, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool} $line
     */
    private function scopeMatchesLine(PromotionScope $scope, array $line): bool
    {
        if ($scope->scopeType() === PromotionScopeType::PRODUCT) {
            return $scope->scopeReferenceId() === $line['productId'];
        }

        $referenceIds = $line['matchingScopeReferenceIds'][$scope->scopeType()->value] ?? [];

        return in_array($scope->scopeReferenceId(), $referenceIds, true);
    }

    /**
     * Money::subtract() throws on a currency mismatch — checked
     * explicitly first so a mismatch produces a clear, specific error
     * rather than an opaque exception from inside subtract().
     */
    private function assertSameCurrency(Money $cartSubtotal, Money $threshold): void
    {
        if (! $cartSubtotal->currency()->equals($threshold->currency())) {
            throw new InvalidArgumentException(
                "Currency mismatch: cart subtotal is {$cartSubtotal->currency()->code()}, ".
                "Promotion spend threshold is {$threshold->currency()->code()}."
            );
        }
    }
}
