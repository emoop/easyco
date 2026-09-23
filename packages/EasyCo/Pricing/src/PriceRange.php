<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Contracts\PriceQuote;
use InvalidArgumentException;

/**
 * A range of already-resolved PriceQuotes across several priceable targets
 * (e.g. every non-archived Variation of one VARIABLE Product) — the pure
 * domain concept behind "от 19.99 лв." / "19.99 - 29.99 лв." style display.
 * See pricing-domain-design.md §4 ("Price ranges") for the full design.
 *
 * Framework-agnostic, like every other class in this package (CLAUDE.md
 * rule 1) — built entirely from already-resolved PriceQuote[] handed in by
 * the caller via fromQuotes(); this class never resolves anything itself
 * and never touches a repository.
 *
 * DELIBERATELY NOT INCLUDED, a decision not an oversight:
 * - Any highest-value/min-max accessor. Min-max display ("19.99 - 29.99 лв.") is
 *   explicitly deferred (pricing-domain-design.md §9) — adding a
 *   highest*() pair later is a pure, additive change once a caller
 *   actually needs it; nothing here should grow speculatively ahead of
 *   that.
 * - isDiscounted(). Its only real use is "is there anything discounted at
 *   all", which is already exactly lowestDiscountedFinalQuote() !== null
 *   at its single call site — a second, redundant boolean would just be
 *   two ways to ask the same question.
 * - pricedCount(). No named call site needs it; Prompt B's own "partially
 *   priced" badge (deferred, §9) is the one plausible future consumer, and
 *   is not being built speculatively ahead of that need either.
 *
 * DETERMINISM: this class never relies on the caller's iteration order,
 * on SQL ORDER BY, or on Variation::sort_order (deliberately not exposed
 * by the domain — see VariationRepository's own docblock). Every "lowest"
 * accessor breaks ties the same way: lowest amount, then (for
 * lowestFinalQuote()) lowest regular amount, then lowest priceableId
 * compared as a plain string, ascending — so the same set of quotes, fed
 * in any order/keying, always produces the identical result.
 */
final class PriceRange
{
    /** @param array<string, PriceQuote> $quotesByPriceableId */
    private function __construct(
        private readonly array $quotesByPriceableId,
    ) {
    }

    /**
     * @param array<string, PriceQuote> $quotesByPriceableId keyed by
     *   priceableId — the SAME shape PriceRangeResolver::resolveQuotes()
     *   returns, so the two are meant to be used together directly:
     *   PriceRange::fromQuotes($rangeResolver->resolveQuotes($contexts)).
     *
     * @throws InvalidArgumentException if the quotes do not all share one
     *   currency, or mix tax-inclusive and tax-exclusive prices. An EMPTY
     *   array is legal (isEmpty() === true) — never an exception; a
     *   Product with nothing resolvable is a normal, expected state
     *   (D3), not an error.
     */
    public static function fromQuotes(array $quotesByPriceableId): self
    {
        self::assertConsistent($quotesByPriceableId);

        return new self($quotesByPriceableId);
    }

    private static function assertConsistent(array $quotesByPriceableId): void
    {
        $currency = null;
        $taxInclusive = null;

        foreach ($quotesByPriceableId as $quote) {
            foreach ([$quote->regular, $quote->final] as $price) {
                if ($currency === null) {
                    $currency = $price->currency();
                } elseif (! $currency->equals($price->currency())) {
                    throw new InvalidArgumentException(
                        "PriceRange cannot mix currencies: \"{$currency->code()}\" and \"{$price->currency()->code()}\"."
                    );
                }

                if ($taxInclusive === null) {
                    $taxInclusive = $price->isTaxInclusive();
                } elseif ($taxInclusive !== $price->isTaxInclusive()) {
                    throw new InvalidArgumentException(
                        'PriceRange cannot mix tax-inclusive and tax-exclusive prices.'
                    );
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->quotesByPriceableId === [];
    }

    /** True only when there is at least one quote and every final gross amount is equal — false for an empty range. */
    public function hasUniformFinalPrice(): bool
    {
        return self::allGrossAmountsEqual(array_map(
            static fn (PriceQuote $q): Price => $q->final,
            $this->quotesByPriceableId
        ));
    }

    /** Same "at least one value, all equal" rule as hasUniformFinalPrice(), over the regular dimension. */
    public function hasUniformRegularPrice(): bool
    {
        return self::allGrossAmountsEqual(array_map(
            static fn (PriceQuote $q): Price => $q->regular,
            $this->quotesByPriceableId
        ));
    }

    /**
     * Same "at least one value, all equal" rule, restricted to the
     * discounted subset (isDiscounted() === true) — false when nothing is
     * discounted at all, since that dimension then has zero values.
     */
    public function hasUniformDiscountedFinalPrice(): bool
    {
        $discountedFinals = array_map(
            static fn (PriceQuote $q): Price => $q->final,
            array_filter($this->quotesByPriceableId, static fn (PriceQuote $q): bool => $q->isDiscounted())
        );

        return self::allGrossAmountsEqual($discountedFinals);
    }

    /** @param Price[] $prices */
    private static function allGrossAmountsEqual(array $prices): bool
    {
        if ($prices === []) {
            return false;
        }

        $first = array_shift($prices)->gross();

        foreach ($prices as $price) {
            if (! $price->gross()->equals($first)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The representative offer for a "от X" display: lowest final gross,
     * tie-broken by lowest regular gross, then lowest priceableId
     * (string, ascending) — see class docblock for why the tie-break is
     * this specific, deterministic chain.
     */
    public function lowestFinalQuote(): ?PriceQuote
    {
        $winner = null;
        $winnerId = null;

        foreach ($this->quotesByPriceableId as $priceableId => $quote) {
            if ($winner === null || self::isLowerFinalQuote($quote, $priceableId, $winner, $winnerId)) {
                $winner = $quote;
                $winnerId = $priceableId;
            }
        }

        return $winner;
    }

    private static function isLowerFinalQuote(PriceQuote $candidate, string $candidateId, PriceQuote $current, string $currentId): bool
    {
        $candidateFinal = $candidate->final->gross()->minorValue();
        $currentFinal = $current->final->gross()->minorValue();

        if ($candidateFinal !== $currentFinal) {
            return $candidateFinal < $currentFinal;
        }

        $candidateRegular = $candidate->regular->gross()->minorValue();
        $currentRegular = $current->regular->gross()->minorValue();

        if ($candidateRegular !== $currentRegular) {
            return $candidateRegular < $currentRegular;
        }

        return strcmp($candidateId, $currentId) < 0;
    }

    /** The minimum regular-price dimension, tie-broken by lowest priceableId. */
    public function lowestRegularPrice(): ?Price
    {
        $winner = null;
        $winnerId = null;
        $winnerAmount = null;

        foreach ($this->quotesByPriceableId as $priceableId => $quote) {
            $amount = $quote->regular->gross()->minorValue();

            if (
                $winner === null
                || $amount < $winnerAmount
                || ($amount === $winnerAmount && strcmp($priceableId, $winnerId) < 0)
            ) {
                $winner = $quote->regular;
                $winnerId = $priceableId;
                $winnerAmount = $amount;
            }
        }

        return $winner;
    }

    /**
     * The minimum final price AMONG QUOTES WHERE isDiscounted() IS TRUE —
     * null when nothing in the range is currently discounted. Same
     * tie-break chain as lowestFinalQuote(), restricted to that subset.
     */
    public function lowestDiscountedFinalQuote(): ?PriceQuote
    {
        $winner = null;
        $winnerId = null;

        foreach ($this->quotesByPriceableId as $priceableId => $quote) {
            if (! $quote->isDiscounted()) {
                continue;
            }

            if ($winner === null || self::isLowerFinalQuote($quote, $priceableId, $winner, $winnerId)) {
                $winner = $quote;
                $winnerId = $priceableId;
            }
        }

        return $winner;
    }
}
