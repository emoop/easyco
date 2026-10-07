<?php

namespace App\Services;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
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
 *  - a method that requires a pickup point appends `pickup point`; an inactive
 *    method appends `inactive`.
 * The ADJUST class mode does not exist yet (stage 5d), so it is not represented.
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
    public function summary(ShippingMethod $method, ?array $classNames = null): string
    {
        $currency = DefaultCurrency::get();

        $parts = $this->core($method, $currency, $classNames);

        if ($method->freeAboveMinor() !== null) {
            $parts[] = __('shipping.summary.free_from', ['amount' => $this->money($method->freeAboveMinor(), $currency)]);
        }

        if ($method->requiresPickupPoint()) {
            $parts[] = __('shipping.summary.pickup_point');
        }

        if (! $method->isActive()) {
            $parts[] = __('shipping.summary.inactive');
        }

        return implode('; ', $parts);
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
                'amount' => $this->money((int) $amount, $currency),
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

    private function money(int $minorUnits, Currency $currency): string
    {
        return $this->formatter->format(Money::fromMinorUnits($minorUnits, $currency)->decimalValue(), $currency);
    }
}
