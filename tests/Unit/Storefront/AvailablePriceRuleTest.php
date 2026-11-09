<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Reader\AvailablePriceRule;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use PHPUnit\Framework\TestCase;

/**
 * P6a: THE ONE rule of which variations a product's «от» price is taken from (the card, the page and the stored sort price all use it): the AVAILABLE ones (priced, in stock); when none is available, all the priced
 * ones; nothing when nothing is priced. The exact cases of the owner's decision.
 */
final class AvailablePriceRuleTest extends TestCase
{
    private function quote(int $minor): PriceQuote
    {
        $price = Price::exclusiveOfTax(Money::fromMinorUnits($minor, 'EUR'), 0);

        return new PriceQuote($price, $price);
    }

    /** @param array<string, PriceQuote> $selected */
    private function minors(array $selected): array
    {
        $minors = array_values(array_map(fn (PriceQuote $q): int => $q->final->gross()->minorValue(), $selected));
        sort($minors);

        return $minors;
    }

    /** ★ the owner's example: sizes at 10 (sold out), 12, 12, 14, 16 → the price comes from 12, 12, 14, 16 («От 12.00») */
    public function test_a_sold_out_cheapest_size_is_ignored_while_another_is_available(): void
    {
        $quotes = ['a' => $this->quote(1000), 'b' => $this->quote(1200), 'c' => $this->quote(1200), 'd' => $this->quote(1400), 'e' => $this->quote(1600)];
        $stock = ['a' => 0, 'b' => 2, 'c' => 1, 'd' => 5, 'e' => 9];

        $selected = AvailablePriceRule::select(['a', 'b', 'c', 'd', 'e'], $quotes, $stock);

        $this->assertSame([1200, 1200, 1400, 1600], $this->minors($selected));
        $this->assertArrayNotHasKey('a', $selected);
    }

    /** ★ when nothing is available the sold-out product still has a price: the lowest of ALL the priced variations */
    public function test_when_none_is_available_all_the_priced_variations_count(): void
    {
        $quotes = ['a' => $this->quote(3000), 'b' => $this->quote(2500)];

        $selected = AvailablePriceRule::select(['a', 'b'], $quotes, ['a' => 0]);

        $this->assertSame([2500, 3000], $this->minors($selected));
    }

    /** ★ nothing priced: nothing (null on the card, NULL in the column) */
    public function test_nothing_priced_selects_nothing(): void
    {
        $this->assertSame([], AvailablePriceRule::select(['a', 'b'], [], ['a' => 5, 'b' => 5]));
        $this->assertSame([], AvailablePriceRule::select([], ['a' => $this->quote(100)], ['a' => 5]));
    }

    public function test_an_unpriced_variation_in_stock_does_not_make_the_priced_sold_out_ones_unavailable_ones(): void
    {
        // `b` has no price but has stock: it is not AVAILABLE (nothing to buy it at), so the priced sold-out `a` is the fallback
        $selected = AvailablePriceRule::select(['a', 'b'], ['a' => $this->quote(900)], ['a' => 0, 'b' => 7]);

        $this->assertSame([900], $this->minors($selected));
    }

    public function test_a_variation_that_is_not_listed_as_shown_is_never_considered(): void
    {
        $quotes = ['shown' => $this->quote(2000), 'hidden' => $this->quote(100)];

        $selected = AvailablePriceRule::select(['shown'], $quotes, ['shown' => 1, 'hidden' => 9]);

        $this->assertSame([2000], $this->minors($selected));
    }

    public function test_integer_minor_units_and_a_missing_stock_row_count_as_zero(): void
    {
        $selected = AvailablePriceRule::select(['a', 'b'], ['a' => $this->quote(1), 'b' => $this->quote(2)], ['b' => 1]);

        $this->assertSame([2], $this->minors($selected), 'a has no stock row = 0: unavailable; b is the only available one');
    }
}
