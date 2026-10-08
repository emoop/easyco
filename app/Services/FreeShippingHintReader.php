<?php

namespace App\Services;

use App\Settings\StoreLocale;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Rating\MethodRate;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Support\Facades\Lang;

/**
 * Picks the ONE method whose free-shipping threshold the storefront should talk
 * about, and writes the sentence (shipping stage 3e, §5.1). A READER: it reads
 * the methods and rates it is given, the formatter and the store locale, and
 * nothing else. It NEVER throws — anything missing (no threshold-carrying
 * method, a rate with no method to name) simply yields null.
 *
 * The rule, over the matched zone's ACTIVE local methods that carry a threshold:
 *  - if any still has a POSITIVE remaining, take the smallest remaining (ties:
 *    lowest sortOrder, then id numerically) -> state "remaining";
 *  - otherwise, if one is already unlocked by its threshold, take the LOWEST
 *    threshold (ties as above) -> state "unlocked";
 *  - otherwise null.
 * CARRIER methods are ignored (their price is quoted live, §5.2) and so is any
 * method with no threshold.
 *
 * The sentence is translated in the STORE locale — App::getLocale() is not the
 * store's on the `api` group, so the locale is passed to the translator
 * explicitly — and the amount is formatted by the project's PriceDisplayFormatter.
 * The only text that reaches the sentence from outside is the merchant's own
 * method NAME (never customer input); it is carried as a plain string and the
 * client renders it escaped, as it does every other merchant string.
 */
final class FreeShippingHintReader
{
    public function __construct(
        private readonly PriceDisplayFormatter $formatter,
        private readonly StoreLocale $storeLocale,
    ) {
    }

    /**
     * @param  list<ShippingMethod>  $methods  the matched zone's offered methods
     * @param  list<MethodRate>  $rates         their rates, from ShippingRateCalculator
     */
    public function read(array $methods, array $rates, string $currency): ?FreeShippingHint
    {
        $byId = [];

        foreach ($methods as $method) {
            $byId[(string) $method->id()] = $method;
        }

        $candidates = [];

        foreach ($rates as $rate) {
            if ($rate->needsQuote() || $rate->freeAboveMinor === null || $rate->remainingToFreeMinor === null) {
                continue; // a CARRIER, or a method with no threshold: nothing to say
            }

            $method = $byId[$rate->methodId] ?? null;

            if ($method === null || ! $method->isActive()) {
                continue; // a rate with no method to name, or an inactive one: skip
            }

            $candidates[] = [$method, $rate];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => self::byPrecedence($a[0], $b[0]));

        return $this->hintFrom(array_map(
            static fn (array $c): array => [
                'id' => (string) $c[0]->id(),
                'name' => $c[0]->name(),
                'courier' => $c[0]->courier(),
                'free' => (int) $c[1]->freeAboveMinor,
                'remaining' => (int) $c[1]->remainingToFreeMinor,
            ],
            $candidates,
        ), $currency);
    }

    /**
     * The same hint, read from the FINAL quote list (stage 4b, §9.1.7): what is left after the merchant's
     * `shipping.quotes` filter, so the hint never names a method the customer is not offered. The list is
     * already in precedence order (sortOrder, id), so it is NOT re-sorted. Unavailable and CARRIER quotes
     * and quotes with no threshold are skipped. A filter that changed an amount changes nothing here: the hint
     * is goods against the threshold, and the threshold facts are the quote's own.
     *
     * @param  list<MethodQuote>  $quotes
     */
    public function readFromQuotes(array $quotes, string $currency): ?FreeShippingHint
    {
        $candidates = [];

        foreach ($quotes as $quote) {
            if (! $quote->isAvailable() || $quote->kind === ShippingMethodKind::CARRIER->value
                || $quote->freeAboveMinor === null || $quote->remainingToFreeMinor === null) {
                continue;
            }

            $candidates[] = [
                'id' => $quote->methodId,
                'name' => $quote->name,
                'courier' => $quote->courier,
                'free' => $quote->freeAboveMinor,
                'remaining' => $quote->remainingToFreeMinor,
            ];
        }

        return $this->hintFrom($candidates, $currency);
    }

    /** @param list<array{id: string, name: string, courier: ?string, free: int, remaining: int}> $candidates already in precedence order */
    private function hintFrom(array $candidates, string $currency): ?FreeShippingHint
    {
        if ($candidates === []) {
            return null;
        }

        $chosen = $this->choose($candidates);

        return $chosen === null ? null : $this->build($chosen[1], $chosen[0], $currency);
    }

    /**
     * @param  list<array{id: string, name: string, courier: ?string, free: int, remaining: int}>  $candidates  already in precedence order
     * @return array{0: array{id: string, name: string, courier: ?string, free: int, remaining: int}, 1: string}|null
     */
    private function choose(array $candidates): ?array
    {
        $best = null;

        foreach ($candidates as $c) {
            if ($c['remaining'] <= 0) {
                continue;
            }

            if ($best === null || $c['remaining'] < $best[0]['remaining']) {
                $best = [$c, FreeShippingHint::REMAINING];
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach ($candidates as $c) {
            if ($best === null || $c['free'] < $best[0]['free']) {
                $best = [$c, FreeShippingHint::UNLOCKED];
            }
        }

        return $best;
    }

    /** @param array{id: string, name: string, courier: ?string, free: int, remaining: int} $c */
    private function build(string $state, array $c, string $currency): FreeShippingHint
    {
        $locale = $this->storeLocale->current();
        // The sentence names the method inside its courier group: "Econt – To office" (stage 5f); no courier = the name as ever.
        $methodName = \EasyCo\Shipping\ShippingCourier::displayName($c['courier'], $c['name']);

        if ($state === FreeShippingHint::REMAINING) {
            $amount = $this->formatter->format(
                Money::fromMinorUnits($c['remaining'], $currency)->decimalValue(),
                Currency::from($currency),
            );

            $text = Lang::get('shipping_hint.remaining', ['amount' => $amount, 'method' => $methodName], $locale);
        } else {
            $text = Lang::get('shipping_hint.unlocked', ['method' => $methodName], $locale);
        }

        return new FreeShippingHint($state, $c['id'], $c['name'], $c['free'], $c['remaining'], $currency, (string) $text);
    }

    /** sortOrder ascending, then id ascending (numerically when both are numbers). */
    private static function byPrecedence(ShippingMethod $a, ShippingMethod $b): int
    {
        if ($a->sortOrder() !== $b->sortOrder()) {
            return $a->sortOrder() <=> $b->sortOrder();
        }

        $idA = $a->id();
        $idB = $b->id();

        if ($idA === $idB) {
            return 0;
        }

        if ($idA === null || $idB === null) {
            return $idA === null ? 1 : -1;
        }

        return ctype_digit($idA) && ctype_digit($idB) ? (int) $idA <=> (int) $idB : strcmp($idA, $idB);
    }
}
