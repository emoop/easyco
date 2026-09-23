<?php

namespace App\Services;

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
 * THIS CLASS IS DELIBERATELY THE ONLY PLACE THE SYMBOL AND ITS POSITION
 * ARE DECIDED TODAY. The symbol table below is a small, hardcoded map —
 * not configuration, not a domain concept, just enough to stop showing
 * a bare decimal in the admin panel right now. The deferred settings
 * task (admin Settings → currency display: an on/off toggle plus a
 * left/right position choice, real keys to be named
 * `site.currency_symbol_enabled` / `site.currency_symbol_position`) will
 * move the source of both the symbol and its position to
 * SiteSettingsRepository — INSIDE THIS CLASS ONLY, with zero call-site
 * changes anywhere that already calls format()/symbolFor().
 *
 * SUFFIX POSITION IS A DELIBERATE, TEMPORARY COMPROMISE, NOT AN
 * OVERSIGHT: format() always renders "{amount} {symbol}" — correct for
 * EUR/BGN and most of the map, but genuinely atypical for USD/GBP
 * (customarily prefixed, "$49.99"/"£49.99"). Accepted for now because
 * this project's home market is EUR/BGN-first (CLAUDE.md's own
 * "Bulgaria adopted the euro" note) and the deferred settings task above
 * is what actually needs to offer a position choice, not this one-shot
 * admin-panel pass.
 */
class PriceDisplayFormatter
{
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
     * Suffix position, separated by a single ordinary space (no NBSP
     * yet — see class docblock's deferred list). An unknown currency's
     * symbol is null, so this returns $decimalValue completely
     * unchanged — today's plain-decimal behaviour, byte for byte.
     */
    public function format(string $decimalValue, Currency $currency): string
    {
        $symbol = $this->symbolFor($currency);

        return $symbol === null ? $decimalValue : "{$decimalValue} {$symbol}";
    }
}
