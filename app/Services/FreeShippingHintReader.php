<?php

namespace App\Services;

use App\Settings\StoreLocale;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
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

        $chosen = $this->choose($candidates);

        if ($chosen === null) {
            return null;
        }

        [$method, $rate, $state] = $chosen;

        return $this->build($state, $method, $rate, $currency);
    }

    /**
     * @param  list<array{0: ShippingMethod, 1: MethodRate}>  $candidates  already in precedence order
     * @return array{0: ShippingMethod, 1: MethodRate, 2: string}|null
     */
    private function choose(array $candidates): ?array
    {
        $best = null;

        foreach ($candidates as [$method, $rate]) {
            if ($rate->remainingToFreeMinor <= 0) {
                continue;
            }

            if ($best === null || $rate->remainingToFreeMinor < $best[1]->remainingToFreeMinor) {
                $best = [$method, $rate, FreeShippingHint::REMAINING];
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach ($candidates as [$method, $rate]) {
            if ($best === null || $rate->freeAboveMinor < $best[1]->freeAboveMinor) {
                $best = [$method, $rate, FreeShippingHint::UNLOCKED];
            }
        }

        return $best;
    }

    private function build(string $state, ShippingMethod $method, MethodRate $rate, string $currency): FreeShippingHint
    {
        $locale = $this->storeLocale->current();
        $methodName = $method->name();

        if ($state === FreeShippingHint::REMAINING) {
            $amount = $this->formatter->format(
                Money::fromMinorUnits($rate->remainingToFreeMinor, $currency)->decimalValue(),
                Currency::from($currency),
            );

            $text = Lang::get('shipping_hint.remaining', ['amount' => $amount, 'method' => $methodName], $locale);
        } else {
            $text = Lang::get('shipping_hint.unlocked', ['method' => $methodName], $locale);
        }

        return new FreeShippingHint(
            $state,
            (string) $method->id(),
            $methodName,
            (int) $rate->freeAboveMinor,
            (int) $rate->remainingToFreeMinor,
            $currency,
            (string) $text,
        );
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
