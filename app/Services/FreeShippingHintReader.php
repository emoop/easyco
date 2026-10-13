<?php

namespace App\Services;

use App\Settings\StoreLocale;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
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
 * The rule, over the matched zone's ACTIVE local methods that carry a threshold
 * (owner decision, shipping-domain-design.md §5.1: a customer who ALREADY has free shipping must be told so, never
 * "add X more" for another method):
 *  - if any is already UNLOCKED (remaining <= 0), take the one with the LOWEST
 *    threshold (ties: lowest sortOrder, then id numerically) -> state "unlocked";
 *  - otherwise take the smallest remaining (ties as above) -> state "remaining";
 *  - otherwise null.
 * CARRIER methods are ignored (their price is quoted live, §5.2) and so is any
 * method with no threshold.
 *
 * KINDS (stage 5g-2b, shipping-domain-design.md 9.3.5): a candidate is a method together with the kinds of delivery its free
 * shipping applies to WITHIN THE SCOPE of the request: the request's own kind (a single-kind quote), the kinds of the matched
 * buckets (the `all` quote), or every kind (the cart hint, which has no destination). A kind counts only when the method serves it
 * and its threshold applies to it — read from ShippingMethod::effectiveFreeDestinations() and the per-kind rate/quote facts,
 * never a second rule. The sentence names those kinds ("to an office or a locker") unless they are EVERY kind the method serves,
 * in which case it is byte for byte what it always was. The hint's `destinations` are always the in-scope free kinds.
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
     * @param  list<ShippingDeliveryType>|null  $scopeKinds  the kinds the request stands for; null = every kind (the cart hint)
     */
    public function read(array $methods, array $rates, string $currency, ?array $scopeKinds = null): ?FreeShippingHint
    {
        $scope = self::scopeValues($scopeKinds);
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

            $kinds = self::inScope($scope, array_map(static fn (ShippingDeliveryType $k): string => $k->value, $method->effectiveFreeDestinations()));

            if ($kinds === []) {
                continue; // free for none of the kinds in scope: this request has nothing to say about it
            }

            $candidates[] = [$method, $rate, $kinds];
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
                'kinds' => $c[2],
                'served' => self::inScope($scope, array_map(static fn (ShippingDeliveryType $k): string => $k->value, $c[0]->servedKinds())),
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
     * The in-scope free kinds of a quote are the scope within the quote's own `freeKinds` (a quote that states none, e.g. one built
     * by hand, names no kinds and so keeps the plain sentence).
     *
     * @param  list<MethodQuote>  $quotes
     * @param  list<ShippingDeliveryType>|null  $scopeKinds  the kinds the quote list stands for; null = every kind
     */
    public function readFromQuotes(array $quotes, string $currency, ?array $scopeKinds = null): ?FreeShippingHint
    {
        $scope = self::scopeValues($scopeKinds);
        $candidates = [];

        foreach ($quotes as $quote) {
            if (! $this->isCandidate($quote)) {
                continue;
            }

            $candidates[] = [
                'id' => $quote->methodId,
                'name' => $quote->name,
                'courier' => $quote->courier,
                'free' => (int) $quote->freeAboveMinor,
                'remaining' => (int) $quote->remainingToFreeMinor,
                'kinds' => self::inScope($scope, $quote->freeKinds ?? []),
                'served' => $quote->servedKinds === null ? null : self::inScope($scope, $quote->servedKinds),
            ];
        }

        return $this->hintFrom($candidates, $currency);
    }

    /**
     * The hint of an `all` quote (stage 5g-2b): read from the FINAL per-kind lists (after the filter, one list per kind of a matched
     * bucket), so a kind the filter removed for a method is not in scope for it. A method is ONE candidate, in order of first
     * appearance (the address list first); its in-scope free kinds are the kinds whose own quote carries the threshold facts, i.e.
     * the kinds it serves and its threshold applies to.
     *
     * @param  array<string, list<MethodQuote>>  $listsByKind  'address' | 'office' | 'locker' => the final quotes of that kind
     */
    public function readFromKindLists(array $listsByKind, string $currency): ?FreeShippingHint
    {
        $byMethod = [];
        // The scope of an `all` quote is the kinds of the buckets that matched a zone (a kind with no list was not asked).
        $scope = array_values(array_filter(['address', 'office', 'locker'], static fn (string $k): bool => isset($listsByKind[$k])));

        foreach (['address', 'office', 'locker'] as $kind) {
            foreach ($listsByKind[$kind] ?? [] as $quote) {
                if (! $this->isCandidate($quote)) {
                    continue;
                }

                $byMethod[$quote->methodId] ??= [
                    'id' => $quote->methodId,
                    'name' => $quote->name,
                    'courier' => $quote->courier,
                    'free' => (int) $quote->freeAboveMinor,
                    'remaining' => (int) $quote->remainingToFreeMinor,
                    'kinds' => [],
                    'served' => $quote->servedKinds === null ? null : self::inScope($scope, $quote->servedKinds),
                ];
                $byMethod[$quote->methodId]['kinds'][] = $kind;
            }
        }

        return $this->hintFrom(array_values($byMethod), $currency);
    }

    /** An available LOCAL quote that carries threshold facts: the only kind of quote a hint can be about. */
    private function isCandidate(MethodQuote $quote): bool
    {
        return $quote->isAvailable() && $quote->kind !== ShippingMethodKind::CARRIER->value
            && $quote->freeAboveMinor !== null && $quote->remainingToFreeMinor !== null;
    }

    /**
     * @param  list<ShippingDeliveryType>|null  $scopeKinds
     * @return list<string> the scope as canonical kind values
     */
    private static function scopeValues(?array $scopeKinds): array
    {
        $given = $scopeKinds === null ? ShippingDeliveryType::destinationKinds() : $scopeKinds;
        $values = array_map(static fn (ShippingDeliveryType $k): string => $k->value, $given);

        return array_values(array_filter(['address', 'office', 'locker'], static fn (string $k): bool => in_array($k, $values, true)));
    }

    /**
     * @param  list<string>  $scope
     * @param  list<string>  $free
     * @return list<string> the free kinds within the scope, in canonical order
     */
    private static function inScope(array $scope, array $free): array
    {
        return array_values(array_filter($scope, static fn (string $k): bool => in_array($k, $free, true)));
    }

    /** @param list<array{id: string, name: string, courier: ?string, free: int, remaining: int, kinds: list<string>, served: ?list<string>}> $candidates already in precedence order */
    private function hintFrom(array $candidates, string $currency): ?FreeShippingHint
    {
        // A method free for none of the kinds in scope has nothing to say (the single-kind quote never names a method that is not free for that kind).
        $candidates = array_values(array_filter($candidates, static fn (array $c): bool => $c['kinds'] !== [] || $c['served'] === null));

        if ($candidates === []) {
            return null;
        }

        $chosen = $this->choose($candidates);

        return $chosen === null ? null : $this->build($chosen[1], $chosen[0], $currency);
    }

    /**
     * @param  list<array{id: string, name: string, courier: ?string, free: int, remaining: int, kinds: list<string>, served: ?list<string>}>  $candidates  already in precedence order
     * @return array{0: array{id: string, name: string, courier: ?string, free: int, remaining: int, kinds: list<string>, served: ?list<string>}, 1: string}|null
     */
    private function choose(array $candidates): ?array
    {
        // The candidates arrive in precedence order, so a strict "<" keeps the earlier one on a tie.
        $unlocked = null;

        foreach ($candidates as $c) {
            if ($c['remaining'] <= 0 && ($unlocked === null || $c['free'] < $unlocked['free'])) {
                $unlocked = $c;
            }
        }

        if ($unlocked !== null) {
            return [$unlocked, FreeShippingHint::UNLOCKED];
        }

        $short = null;

        foreach ($candidates as $c) {
            if ($short === null || $c['remaining'] < $short['remaining']) {
                $short = $c;
            }
        }

        return $short === null ? null : [$short, FreeShippingHint::REMAINING];
    }

    /** @param array{id: string, name: string, courier: ?string, free: int, remaining: int, kinds: list<string>, served: ?list<string>} $c */
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

            $text = $this->namesKinds($c)
                ? Lang::get('shipping_hint.remaining_kinds', ['amount' => $amount, 'kinds' => $this->kindsPhrase($c['kinds'], $locale), 'method' => $methodName], $locale)
                : Lang::get('shipping_hint.remaining', ['amount' => $amount, 'method' => $methodName], $locale);
        } else {
            $text = $this->namesKinds($c)
                ? Lang::get('shipping_hint.unlocked_kinds', ['kinds' => $this->kindsPhrase($c['kinds'], $locale), 'method' => $methodName], $locale)
                : Lang::get('shipping_hint.unlocked', ['method' => $methodName], $locale);
        }

        return new FreeShippingHint($state, $c['id'], $c['name'], $c['free'], $c['remaining'], $currency, (string) $text, $c['kinds']);
    }

    /**
     * The kinds phrase is used only when the in-scope free kinds are NOT every kind the method serves WITHIN the scope of the request
     * (`served` is already cut to the scope); otherwise the sentence is the one it always was. So a single-kind quote, which only names a
     * method that is free for the asked kind, keeps the plain sentence, and the cart hint and the `all` quote say "to an office or a locker".
     */
    private function namesKinds(array $c): bool
    {
        return $c['kinds'] !== [] && $c['served'] !== null && $c['kinds'] !== $c['served'];
    }

    /**
     * "an office", "an office or a locker", "an address, an office or a locker" (bg: "офис", "офис или автомат", "адрес, офис или автомат").
     *
     * @param  list<string>  $kinds  canonical order
     */
    private function kindsPhrase(array $kinds, string $locale): string
    {
        $words = array_map(static fn (string $k): string => (string) Lang::get('shipping_hint.kinds.'.$k, [], $locale), $kinds);
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words).' '.Lang::get('shipping_hint.or', [], $locale).' '.$last;
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
