<?php

namespace App\Services;

use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Currency;

/**
 * Formats an already-resolved decimal price string for display, with an
 * optional currency symbol — display only, never used for calculation
 * (the same posture Money::decimalValue()/Price's own docblocks already
 * establish: formatting and arithmetic are deliberately separate
 * concerns).
 *
 * THE SYMBOL ALWAYS COMES FROM THE PRICE'S OWN CURRENCY
 * ($price->gross()->currency()), passed in by the caller — never a
 * fresh EasyCo\Pricing\DefaultCurrency::get() read at render time. A
 * resolved PriceQuote already carries the currency it was actually
 * resolved in; re-deriving it from the store's current default would be
 * a second, potentially-diverging source of truth for the exact same
 * fact.
 *
 * THE SYMBOL TABLE ITSELF STAYS A SMALL, HARDCODED MAP — not
 * configuration, not a domain concept, just enough to stop showing a
 * bare decimal. WHICH CURRENCY THE STORE ACTUALLY USES is a separate,
 * deliberately .env-only decision (PRICING_DEFAULT_CURRENCY,
 * EasyCo\Pricing\DefaultCurrency) — every already-saved price/cost is
 * keyed by currency code at write time, so a merchant-facing currency
 * picker was ruled out (would silently orphan existing prices, not
 * convert them). ONLY THE POSITION IS MERCHANT-CONFIGURABLE, per the
 * originally-deferred settings task this finishes: `site.currency_symbol_
 * position`, one of 'prefix' | 'prefix_space' | 'suffix' | 'suffix_space'
 * — WooCommerce's own well-tested 4-option model, chosen over two
 * separate booleans (position + spacing) to avoid inventing
 * combinations no real platform actually ships. Defaults to
 * 'suffix_space' when unset — the exact, byte-for-byte behavior this
 * class had before this setting existed ("{amount} {symbol}"), so an
 * upgraded installation that never visits the new Settings tab renders
 * identically to before.
 *
 * THIS CLASS HOLDS NO STATE OF ITS OWN, and the position is still read on
 * every format() call — that read is now an in-memory array lookup, because
 * SiteSettingsRepository itself memoizes per request (see that
 * implementation's own docblock). This class used to keep a private copy of
 * the position precisely because the repository had no memory; keeping it now
 * would be worse than redundant: it would serve the pre-write value for the
 * rest of the request even after a caller had set a new one, which is exactly
 * the behaviour that was deliberately removed.
 */
class PriceDisplayFormatter
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {}

    /**
     * ISO 4217 code => display symbol. An unknown currency (anything not
     * in EasyCo\Pricing\Currency::KNOWN, or simply not added here yet)
     * maps to null via symbolFor() — format() then falls back to a
     * plain decimal, today's existing behaviour, never a broken/blank
     * render.
     */
    private const SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'BGN' => 'лв.',
        'CHF' => 'CHF',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'RON' => 'lei',
        'HUF' => 'Ft',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'JPY' => '¥',
        'CNY' => '¥',
        'INR' => '₹',
        'TRY' => '₺',
        'KRW' => '₩',
        'CAD' => 'C$',
        'AUD' => 'A$',
        // No distinct symbol convention for these three in common use —
        // their own ISO code doubles as the "symbol" rather than being
        // treated as unknown (null), per this task's own explicit
        // decision.
        'KWD' => 'KWD',
        'BHD' => 'BHD',
        'OMR' => 'OMR',
    ];

    public function symbolFor(Currency $currency): ?string
    {
        return self::SYMBOLS[$currency->code()] ?? null;
    }

    /**
     * The position, read on EVERY call (no memo here — see the class docblock
     * for why a private copy of it would now be a bug rather than a saving).
     * An unknown currency's symbol is null, so this returns $decimalValue
     * completely unchanged regardless of position — today's plain-decimal
     * behaviour, byte for byte.
     */
    public function format(string $decimalValue, Currency $currency): string
    {
        $symbol = $this->symbolFor($currency);

        if ($symbol === null) {
            return $decimalValue;
        }

        $position = $this->settings->get('site.currency_symbol_position') ?? 'suffix_space';

        return match ($position) {
            'prefix' => "{$symbol}{$decimalValue}",
            'prefix_space' => "{$symbol} {$decimalValue}",
            'suffix' => "{$decimalValue}{$symbol}",
            default => "{$decimalValue} {$symbol}",
        };
    }
}
