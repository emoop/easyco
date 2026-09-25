<?php

namespace App\Services;

use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\PriceRange;

/**
 * THE ONE PRICE-RANGE DISPLAY RULE — Prompt D, D6: "the admin's price
 * rendering (ProductResource::priceRangeHtml() + App\Services\
 * PriceDisplayFormatter) is the display rule. Do not re-implement it."
 *
 * MOVED HERE VERBATIM, not rewritten: rangeHtml() below is the exact body
 * ProductResource::priceRangeHtml() had (same '—' for null/empty, same
 * lowestFinalQuote(), same e() escaping, same `<s>{$regular}</s>
 * {$final}` for a discounted quote, same `products.price_from` prefix
 * when the range's final price is not uniform). ProductResource's own
 * method is now a thin delegate to this class, so the admin table's
 * rendered output is byte-for-byte what it was — proven by the existing
 * admin tests passing unchanged (tests/Feature/
 * ProductResourcePriceColumnTest.php, untouched by this task).
 *
 * WHY THE EXTRACTION WAS NECESSARY AT ALL: the rule lived as a public
 * static method ON A FILAMENT RESOURCE (`ProductResource::priceRangeHtml()`).
 * A storefront view calling a Filament Resource class would drag the
 * admin panel into the customer-facing path — the exact coupling
 * admin-panel-design.md §2 (packages never depend on a specific admin UI)
 * and §1's app/-layer composition rule reject. An app service is the
 * smallest thing both surfaces can share without either one owning it.
 *
 * NOT BOUND scoped() (deliberately, and unlike PriceDisplayFormatter/
 * ProductPriceRangeProvider/OrderAdminReader): this class holds NO state
 * of its own — no cache, no memoized value, nothing that could go stale
 * between two calls. scoped() exists in this codebase to make a
 * stateful instance the SAME instance across one request; binding a
 * stateless delegate would only make `app()` calls return an identical
 * empty object, at the cost of implying state that is not there. Its one
 * collaborator (PriceDisplayFormatter) IS scoped(), so the per-request
 * memoization that actually matters still happens, exactly once.
 *
 * quoteHtml() IS THE SAME RULE FOR ONE ALREADY-RESOLVED QUOTE, added for
 * D5's per-variation rows: a variation has ONE PriceQuote, not a range,
 * and the sandbox must still render it through this one rule rather than
 * inventing a second `price + symbol` implementation. rangeHtml() itself
 * is built out of quoteHtml() — the `<s>regular</s> final` markup exists
 * in exactly one place in the codebase, not two that could drift.
 */
final class ProductPriceDisplay
{
    public function __construct(
        private readonly PriceDisplayFormatter $formatter,
    ) {
    }

    /**
     * The admin product table's own price cell, and (identical by
     * construction) the sandbox list's and product page's product-level
     * price. '<s>…</s>' is real markup, so every caller renders this with
     * an unescaped/HTML-allowing output path — the admin's ->html()
     * column and the sandbox's Blade `{!! !!}` — exactly as
     * ProductResource's own docblock already required.
     */
    public function rangeHtml(?PriceRange $priceRange): string
    {
        if ($priceRange === null || $priceRange->isEmpty()) {
            return '—';
        }

        $html = $this->quoteHtml($priceRange->lowestFinalQuote());

        if (! $priceRange->hasUniformFinalPrice()) {
            $html = e(__('products.price_from')).' '.$html;
        }

        return $html;
    }

    /**
     * One quote, same markup rule — null (nothing resolvable for this
     * priceable target) renders '—', never an error: D5's own requirement
     * for a variation with no configured price.
     */
    public function quoteHtml(?PriceQuote $quote): string
    {
        if ($quote === null) {
            return '—';
        }

        return $this->priceHtml($quote->regular->gross(), $quote->final->gross());
    }

    /**
     * THE `<s>regular</s> final` UNIT OF THE RULE, for a caller that has
     * two already-resolved Money amounts rather than a PriceQuote
     * (e.g. the Orders admin View page reading a §3.13 sale-line
     * snapshot's stored regular/final unit prices — admin-panel-
     * design.md §14). quoteHtml() delegates here, so the markup exists in
     * exactly one place; "regular and final differ" is the same condition
     * PriceQuote::isDiscounted() expresses (gross regular != gross final),
     * just without a quote to ask.
     *
     * Every interpolated amount is escaped here, at the single place the
     * string is built — a caller must not have to remember to escape it
     * again (and a caller that did would double-escape).
     */
    public function priceHtml(Money $regular, Money $final): string
    {
        $regularHtml = e($this->formatter->format($regular->decimalValue(), $regular->currency()));
        $finalHtml = e($this->formatter->format($final->decimalValue(), $final->currency()));

        return $regular->equals($final) ? $finalHtml : "<s>{$regularHtml}</s> {$finalHtml}";
    }
}
