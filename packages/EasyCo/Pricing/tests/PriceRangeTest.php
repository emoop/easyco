<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceRange;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PriceRangeTest extends TestCase
{
    /** inclusiveOfTax so gross() equals the input amount exactly, with no rounding noise in assertions. */
    private function price(string $amount, string $currency = 'EUR'): Price
    {
        return Price::inclusiveOfTax(Money::fromDecimal($amount, $currency), 2000);
    }

    private function quote(string $regular, ?string $final = null): PriceQuote
    {
        return new PriceQuote(
            regular: $this->price($regular),
            final: $this->price($final ?? $regular)
        );
    }

    public function test_empty_set_is_empty_and_every_accessor_degrades_gracefully(): void
    {
        $range = PriceRange::fromQuotes([]);

        $this->assertTrue($range->isEmpty());
        $this->assertFalse($range->hasUniformFinalPrice());
        $this->assertFalse($range->hasUniformRegularPrice());
        $this->assertFalse($range->hasUniformDiscountedFinalPrice());
        $this->assertNull($range->lowestFinalQuote());
        $this->assertNull($range->lowestRegularPrice());
        $this->assertNull($range->lowestDiscountedFinalQuote());
    }

    public function test_single_quote_is_uniform_and_is_its_own_lowest(): void
    {
        $quote = $this->quote('19.99');
        $range = PriceRange::fromQuotes(['v1' => $quote]);

        $this->assertFalse($range->isEmpty());
        $this->assertTrue($range->hasUniformFinalPrice());
        $this->assertTrue($range->hasUniformRegularPrice());
        $this->assertFalse($range->hasUniformDiscountedFinalPrice(), 'not discounted, so the discounted dimension has zero values');
        $this->assertSame($quote, $range->lowestFinalQuote());
        $this->assertTrue($range->lowestRegularPrice()->gross()->equals(Money::fromDecimal('19.99', 'EUR')));
        $this->assertNull($range->lowestDiscountedFinalQuote());
    }

    public function test_uniform_multiples_all_equal(): void
    {
        $range = PriceRange::fromQuotes([
            'v1' => $this->quote('19.99'),
            'v2' => $this->quote('19.99'),
            'v3' => $this->quote('19.99'),
        ]);

        $this->assertTrue($range->hasUniformFinalPrice());
        $this->assertTrue($range->hasUniformRegularPrice());
    }

    public function test_mixed_prices_are_not_uniform(): void
    {
        $range = PriceRange::fromQuotes([
            'v1' => $this->quote('19.99'),
            'v2' => $this->quote('24.99'),
        ]);

        $this->assertFalse($range->hasUniformFinalPrice());
        $this->assertFalse($range->hasUniformRegularPrice());
    }

    public function test_the_lowest_final_is_not_discounted_while_another_is(): void
    {
        $range = PriceRange::fromQuotes([
            // Lowest final overall, but NOT discounted.
            'v1' => $this->quote('9.99'),
            // Higher final, but discounted (regular 29.99 -> final 14.99).
            'v2' => $this->quote('29.99', '14.99'),
        ]);

        $lowestFinal = $range->lowestFinalQuote();
        $this->assertTrue($lowestFinal->final->gross()->equals(Money::fromDecimal('9.99', 'EUR')));
        $this->assertFalse($lowestFinal->isDiscounted());

        $lowestDiscounted = $range->lowestDiscountedFinalQuote();
        $this->assertNotNull($lowestDiscounted);
        $this->assertTrue($lowestDiscounted->final->gross()->equals(Money::fromDecimal('14.99', 'EUR')));
        $this->assertTrue($lowestDiscounted->isDiscounted());
    }

    /** Same quotes submitted in reversed/rotated key order must produce the identical winner. */
    public function test_order_independence_of_lowest_final_quote(): void
    {
        $quotes = [
            'v1' => $this->quote('29.99'),
            'v2' => $this->quote('9.99'),
            'v3' => $this->quote('19.99'),
        ];

        $forward = PriceRange::fromQuotes($quotes)->lowestFinalQuote();
        $reversed = PriceRange::fromQuotes(array_reverse($quotes, true))->lowestFinalQuote();

        $rotated = ['v3' => $quotes['v3'], 'v1' => $quotes['v1'], 'v2' => $quotes['v2']];
        $rotatedResult = PriceRange::fromQuotes($rotated)->lowestFinalQuote();

        $this->assertTrue($forward->final->gross()->equals(Money::fromDecimal('9.99', 'EUR')));
        $this->assertTrue($reversed->final->gross()->equals(Money::fromDecimal('9.99', 'EUR')));
        $this->assertTrue($rotatedResult->final->gross()->equals(Money::fromDecimal('9.99', 'EUR')));
    }

    public function test_equal_finals_with_different_regulars_pick_the_lowest_regular_deterministically(): void
    {
        $quotes = [
            // Same final (14.99) but different regular (came from a
            // bigger vs. smaller original discount).
            'v1' => $this->quote('29.99', '14.99'),
            'v2' => $this->quote('19.99', '14.99'),
        ];

        $winnerA = PriceRange::fromQuotes($quotes)->lowestFinalQuote();
        $winnerB = PriceRange::fromQuotes(array_reverse($quotes, true))->lowestFinalQuote();

        $this->assertTrue($winnerA->regular->gross()->equals(Money::fromDecimal('19.99', 'EUR')));
        $this->assertTrue($winnerB->regular->gross()->equals(Money::fromDecimal('19.99', 'EUR')));
    }

    public function test_equal_final_and_regular_tie_breaks_on_lowest_priceable_id_string(): void
    {
        $quotes = [
            'variation-9' => $this->quote('19.99'),
            'variation-10' => $this->quote('19.99'),
            'variation-2' => $this->quote('19.99'),
        ];

        // String comparison, not numeric: "variation-10" < "variation-2"
        // < "variation-9" lexicographically, so "variation-10" must win.
        $winner = PriceRange::fromQuotes($quotes)->lowestFinalQuote();
        $this->assertSame($quotes['variation-10'], $winner);

        $rotated = ['variation-2' => $quotes['variation-2'], 'variation-9' => $quotes['variation-9'], 'variation-10' => $quotes['variation-10']];
        $winnerRotated = PriceRange::fromQuotes($rotated)->lowestFinalQuote();

        // Same winning quote object regardless of submission order —
        // determinism is the point, not any one specific id.
        $this->assertSame($winner, $winnerRotated);
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceRange::fromQuotes([
            'v1' => $this->quote('19.99'),
            'v2' => new PriceQuote(
                regular: $this->price('19.99', 'USD'),
                final: $this->price('19.99', 'USD')
            ),
        ]);
    }

    public function test_mixed_tax_basis_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceRange::fromQuotes([
            // This class's own price() helper is inclusiveOfTax by
            // default — this one deliberately breaks that basis.
            'v1' => new PriceQuote(
                regular: Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000),
                final: Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000)
            ),
            'v2' => $this->quote('19.99'),
        ]);
    }

    public function test_all_three_has_uniform_members_false_on_an_empty_range(): void
    {
        $range = PriceRange::fromQuotes([]);

        $this->assertFalse($range->hasUniformFinalPrice());
        $this->assertFalse($range->hasUniformRegularPrice());
        $this->assertFalse($range->hasUniformDiscountedFinalPrice());
    }
}
