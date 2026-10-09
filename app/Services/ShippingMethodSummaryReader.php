<?php

namespace App\Services;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;

/**
 * The ONE reader that turns a ShippingMethod into the single sentence a merchant
 * reads (shipping-domain-design.md §12.4): built from the method's own getters
 * (`kind()`, `amountMinor()`, `classRates()`, `freeAboveMinor()`,
 * `requiresPickupPoint()`, `isActive()`, `carrierCode()`) plus the class NAMES from
 * `ShippingClassRepository`, money formatted by the project's PriceDisplayFormatter.
 * It reads; it never prices and never writes.
 *
 * The shapes (money in the store currency; class names, not codes):
 *  - FLAT   `5.00 €`
 *  - FLAT+  `5.00 €; free from 100.00 €`
 *  - FREE   `Free`
 *  - PER_CLASS `5.00 €; Heavy 30.00 €; free from 100.00 €` (base, then the class
 *    rates by class name, then the threshold)
 *  - CARRIER `Carrier: econt · not configured`
 *  - every method also states its destination SCOPE (stage 6d): `Address only`, `Pickup point only` or
 *    `Serves any destination`; an inactive method appends `inactive`.
 * An ADJUST method (stage 5d) reads its class amounts as signed adjustments: `5.00 €; Heavy +25.00 €; Discount −3.00 €`.
 *
 * The separator and every fragment are lang keys (`lang/*\/shipping.php`); the
 * whole reader is used by the overview and by "Try it".
 */
final class ShippingMethodSummaryReader
{
    /** code => name, read at most ONCE per instance so a page of many methods stays at one query. */
    private ?array $classNamesCache = null;

    public function __construct(
        private readonly ShippingClassRepository $classes,
        private readonly PriceDisplayFormatter $formatter,
    ) {
    }

    /**
     * @param  array<string, string>|null  $classNames  code => name, when the caller already
     *         read the class list (so a page of many methods stays at ONE classes query);
     *         null makes the reader read it itself (and cache it for its next call)
     */
    public function summary(ShippingMethod $method, ?array $classNames = null, bool $withGrouping = true): string
    {
        $currency = DefaultCurrency::get();

        $parts = $this->core($method, $currency, $classNames);

        // Courier and delivery type lead the sentence (stage 5f) — "Econt · to office; 5.00 €" — unless the caller
        // shows them in columns of their own.
        if ($withGrouping && ($grouping = $this->grouping($method)) !== null) {
            array_unshift($parts, $grouping);
        }

        if ($method->freeAboveMinor() !== null) {
            $parts[] = __('shipping.summary.free_from', ['amount' => $this->money($method->freeAboveMinor(), $currency)]);
        }

        // The destination SCOPE in words (stage 6d, design 9.2.8): "Address only" / "Pickup point only" / "Serves any
        // destination" — the one sentence says where a method may go.
        $parts[] = __('shipping.summary.scope.'.$method->destinationScope()->value);

        if (! $method->isActive()) {
            $parts[] = __('shipping.summary.inactive');
        }

        return implode('; ', $parts);
    }

    /**
     * "Econt · to office", "Econt", "to office" — the courier group of a method as text from the lang files, or null
     * when it has neither (stage 5f). The one place this phrase is built.
     */
    public function grouping(ShippingMethod $method): ?string
    {
        $parts = array_values(array_filter([
            $method->courier(),
            $method->deliveryType() === null ? null : __('shipping.methods.delivery_types_lower.'.$method->deliveryType()->value),
        ], static fn (?string $part): bool => $part !== null));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array<string, string>|null  $classNames
     * @return list<string>
     */
    private function core(ShippingMethod $method, Currency $currency, ?array $classNames): array
    {
        return match ($method->kind()) {
            ShippingMethodKind::FREE => [__('shipping.summary.free')],
            ShippingMethodKind::CARRIER => [
                __('shipping.summary.carrier', ['code' => (string) $method->carrierCode()]),
            ],
            ShippingMethodKind::FLAT => [$this->money((int) $method->amountMinor(), $currency)],
            ShippingMethodKind::PER_CLASS => $this->perClassParts($method, $currency, $classNames),
        };
    }

    /**
     * The base price first, then each class rate keyed by the class NAME.
     *
     * @param  array<string, string>|null  $classNames
     * @return list<string>
     */
    private function perClassParts(ShippingMethod $method, Currency $currency, ?array $classNames): array
    {
        $names = $classNames ?? $this->classNames();

        $parts = [$this->money((int) $method->amountMinor(), $currency)];

        foreach ($method->classRates() as $classCode => $amount) {
            $classCode = (string) $classCode;
            $parts[] = __('shipping.summary.class_rate', [
                'class' => $names[$classCode] ?? $classCode,
                // ADJUST (shipping-domain-design.md §12.2/§12.4): a signed adjustment — "+25.00 €" / "−3.00 €".
                'amount' => $method->classMode() === ShippingClassMode::ADJUST
                    ? $this->signedMoney((int) $amount, $currency)
                    : $this->money((int) $amount, $currency),
            ]);
        }

        return $parts;
    }

    /** @return array<string, string> code => name */
    private function classNames(): array
    {
        if ($this->classNamesCache !== null) {
            return $this->classNamesCache;
        }

        $names = [];

        foreach ($this->classes->all() as $class) {
            $names[$class->code()] = $class->name();
        }

        return $this->classNamesCache = $names;
    }

    /** A signed adjustment: a plus for a surcharge, a true minus sign (U+2212) for a discount, nothing for zero. */
    private function signedMoney(int $minorUnits, Currency $currency): string
    {
        $text = $this->money(abs($minorUnits), $currency);

        return match (true) {
            $minorUnits > 0 => '+'.$text,
            $minorUnits < 0 => "\u{2212}".$text,
            default => $text,
        };
    }

    private function money(int $minorUnits, Currency $currency): string
    {
        return $this->formatter->format(Money::fromMinorUnits($minorUnits, $currency)->decimalValue(), $currency);
    }
}
