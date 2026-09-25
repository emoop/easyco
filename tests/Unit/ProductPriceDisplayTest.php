<?php

namespace Tests\Unit;

use App\Services\PriceDisplayFormatter;
use App\Services\ProductPriceDisplay;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use PHPUnit\Framework\TestCase;

/**
 * ProductPriceDisplay::priceHtml() — the extracted `<s>regular</s> final`
 * rule (admin-panel-design.md §14 stage 5 D3). Stays a plain, DB-free
 * Unit test with a hand-written SiteSettingsRepository fake, exactly like
 * PriceDisplayFormatterTest's own — priceHtml() is the ONE place the
 * struck-through markup is built, and quoteHtml()/rangeHtml() both go
 * through it, so its two branches (equal / different) are what the whole
 * admin price-display family rests on.
 */
final class ProductPriceDisplayTest extends TestCase
{
    private function display(): ProductPriceDisplay
    {
        return new ProductPriceDisplay(new PriceDisplayFormatter(new class implements SiteSettingsRepository
        {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): void {}

            public function forget(string $key): void {}
        }));
    }

    /** Regular == final: the plain final amount, no struck regular price. */
    public function test_price_html_shows_the_final_amount_alone_when_regular_equals_final(): void
    {
        $this->assertSame('19.99 €', $this->display()->priceHtml(
            Money::fromDecimal('19.99', 'EUR'),
            Money::fromDecimal('19.99', 'EUR'),
        ));
    }

    /** Regular != final: the regular amount struck through, then the final one. */
    public function test_price_html_strikes_the_regular_amount_when_it_differs_from_the_final_one(): void
    {
        $this->assertSame('<s>29.99 €</s> 19.99 €', $this->display()->priceHtml(
            Money::fromDecimal('29.99', 'EUR'),
            Money::fromDecimal('19.99', 'EUR'),
        ));
    }

    /**
     * quoteHtml() is now a delegate — proven by producing byte-identical
     * output to priceHtml() for the same pair, through the real
     * PriceQuote/Price objects its callers actually pass.
     */
    public function test_quote_html_produces_the_same_markup_as_price_html(): void
    {
        $quote = new PriceQuote(
            regular: Price::inclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 0),
            final: Price::inclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 0),
        );

        $this->assertSame(
            $this->display()->priceHtml(Money::fromDecimal('29.99', 'EUR'), Money::fromDecimal('19.99', 'EUR')),
            $this->display()->quoteHtml($quote),
        );
    }

    /** A null quote keeps its own '—' (nothing resolvable), never an error. */
    public function test_quote_html_renders_a_dash_for_a_null_quote(): void
    {
        $this->assertSame('—', $this->display()->quoteHtml(null));
    }
}
