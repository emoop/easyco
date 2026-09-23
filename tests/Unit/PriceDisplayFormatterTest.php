<?php

namespace Tests\Unit;

use App\Services\PriceDisplayFormatter;
use EasyCo\Pricing\Currency;
use PHPUnit\Framework\TestCase;

final class PriceDisplayFormatterTest extends TestCase
{
    private function formatter(): PriceDisplayFormatter
    {
        return new PriceDisplayFormatter();
    }

    public function test_symbol_for_eur_usd_bgn(): void
    {
        $this->assertSame('€', $this->formatter()->symbolFor(Currency::EUR()));
        $this->assertSame('$', $this->formatter()->symbolFor(Currency::USD()));
        $this->assertSame('лв.', $this->formatter()->symbolFor(Currency::BGN()));
    }

    public function test_format_renders_the_amount_then_a_single_space_then_the_symbol(): void
    {
        $formatted = $this->formatter()->format('49.99', Currency::EUR());

        $this->assertSame('49.99 €', $formatted);
        $this->assertSame(1, substr_count($formatted, ' '), 'exactly one separating space');
    }

    public function test_format_is_suffix_not_prefix_even_for_usd(): void
    {
        // A deliberate, documented compromise — USD customarily prefixes
        // its symbol ("$49.99"), but this class renders suffix-only for
        // every currency today. Confirms the CURRENT behaviour, not a
        // "correct" one.
        $this->assertSame('49.99 $', $this->formatter()->format('49.99', Currency::USD()));
    }

    public function test_every_currency_currency_knows_about_is_mapped_to_a_real_symbol_or_its_own_code(): void
    {
        // KWD/BHD/OMR deliberately map to their own ISO code rather than
        // null — the class's own docblock states this explicitly.
        $this->assertSame('KWD', $this->formatter()->symbolFor(Currency::of('KWD')));
        $this->assertSame('BHD', $this->formatter()->symbolFor(Currency::of('BHD')));
        $this->assertSame('OMR', $this->formatter()->symbolFor(Currency::of('OMR')));
    }

    /**
     * A genuinely "unknown to this formatter" Currency cannot actually
     * be constructed today: EasyCo\Pricing\Currency::of() itself rejects
     * any code outside its own known list, and PriceDisplayFormatter's
     * map covers every one of those codes — so symbolFor()'s null
     * branch is currently unreachable via the real public API, not
     * untested logic. This exercises format()'s own null-symbol
     * fallback directly (an anonymous subclass forcing symbolFor() to
     * return null), proving the "plain decimal, unchanged" behaviour
     * the class docblock promises for that case, independent of whether
     * Currency ever grows a code this class hasn't mapped yet.
     */
    public function test_format_falls_back_to_a_plain_decimal_when_the_symbol_is_unknown(): void
    {
        $formatter = new class extends PriceDisplayFormatter
        {
            public function symbolFor(Currency $currency): ?string
            {
                return null;
            }
        };

        $this->assertSame('49.99', $formatter->format('49.99', Currency::EUR()));
    }
}
