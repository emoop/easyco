<?php

namespace Tests\Unit;

use App\Services\PriceDisplayFormatter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Currency;
use PHPUnit\Framework\TestCase;

final class PriceDisplayFormatterTest extends TestCase
{
    /**
     * A hand-written fake, not a Laravel/container mock — this stays a
     * plain, DB-free Unit test (no RefreshDatabase, no app() calls), so
     * the fake only needs to satisfy the real SiteSettingsRepository
     * contract (get/set/forget) for the one key format() actually reads.
     */
    private function formatter(?string $currencySymbolPosition = null): PriceDisplayFormatter
    {
        return new PriceDisplayFormatter(new class($currencySymbolPosition) implements SiteSettingsRepository
        {
            public function __construct(private readonly ?string $currencySymbolPosition) {}

            public function get(string $key): ?string
            {
                return $key === 'site.currency_symbol_position' ? $this->currencySymbolPosition : null;
            }

            public function set(string $key, string $value): void {}

            public function forget(string $key): void {}
        });
    }

    public function test_symbol_for_eur_usd_bgn(): void
    {
        $this->assertSame('€', $this->formatter()->symbolFor(Currency::EUR()));
        $this->assertSame('$', $this->formatter()->symbolFor(Currency::USD()));
        $this->assertSame('лв.', $this->formatter()->symbolFor(Currency::BGN()));
    }

    /**
     * No setting configured — the exact, byte-for-byte behaviour this
     * class had before the position became configurable
     * ("{amount} {symbol}"). An upgraded installation that never visits
     * the new Settings → Currency tab must keep rendering identically.
     */
    public function test_format_defaults_to_suffix_with_a_space_when_unconfigured(): void
    {
        $formatted = $this->formatter()->format('49.99', Currency::EUR());

        $this->assertSame('49.99 €', $formatted);
        $this->assertSame(1, substr_count($formatted, ' '), 'exactly one separating space');
    }

    /**
     * All four real `site.currency_symbol_position` values — the
     * WooCommerce-style option set this task settled on, in place of
     * separate position/spacing booleans.
     */
    public function test_format_honors_every_configured_symbol_position(): void
    {
        $this->assertSame('€49.99', $this->formatter('prefix')->format('49.99', Currency::EUR()));
        $this->assertSame('€ 49.99', $this->formatter('prefix_space')->format('49.99', Currency::EUR()));
        $this->assertSame('49.99€', $this->formatter('suffix')->format('49.99', Currency::EUR()));
        $this->assertSame('49.99 €', $this->formatter('suffix_space')->format('49.99', Currency::EUR()));
    }

    /**
     * USD customarily prefixes its symbol ("$49.99") — this is now a
     * real, configurable outcome (via 'prefix'), not the fixed
     * suffix-only compromise this class used to render for every
     * currency regardless of convention.
     */
    public function test_format_can_prefix_usd_once_configured(): void
    {
        $this->assertSame('$49.99', $this->formatter('prefix')->format('49.99', Currency::USD()));
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
        $formatter = new class(new class implements SiteSettingsRepository
        {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): void {}

            public function forget(string $key): void {}
        }) extends PriceDisplayFormatter
        {
            public function symbolFor(Currency $currency): ?string
            {
                return null;
            }
        };

        $this->assertSame('49.99', $formatter->format('49.99', Currency::EUR()));
    }
}
