<?php

namespace EasyCo\Pricing;

/**
 * A single, host-application-configured default Currency, for the rare
 * case where a Money-adjacent computation needs *some* currency before
 * any real amount has established one (e.g.
 * EasyCo\OperationalSales\InstallmentPlan::outstandingBalance() on a
 * plan with zero attached lines yet).
 *
 * Framework-agnostic, like every other class in this package: this is a
 * plain static holder, not a Laravel facade or container binding. The
 * host application is responsible for calling set() once, at boot, from
 * whatever config source it uses (see
 * EasyCo\Pricing\Providers\PricingServiceProvider::boot(), which reads
 * it from Laravel's config('services.pricing.default_currency')) — this
 * class itself never reads Illuminate\Support\Facades\Config or
 * anything else Laravel-specific.
 *
 * DELIBERATELY NOT hardcoded to any single currency, and DELIBERATELY
 * NOT silently defaulted if never configured: a hardcoded fallback here
 * would just move the exact bug this class exists to fix — EasyCo\
 * OperationalSales\InstallmentPlan previously hardcoded BGN as its
 * empty-plan currency fallback, which stopped being legal tender in
 * Bulgaria on 2026-02-01 — to a different currency and a later date.
 * get() throws if the host application never configured one, rather
 * than guessing.
 */
final class DefaultCurrency
{
    private static ?Currency $currency = null;

    public static function set(Currency $currency): void
    {
        self::$currency = $currency;
    }

    public static function get(): Currency
    {
        if (self::$currency === null) {
            throw new \LogicException(
                'No default Currency has been configured. The host application must call '.
                'EasyCo\Pricing\DefaultCurrency::set() once at boot — see PricingServiceProvider::boot().'
            );
        }

        return self::$currency;
    }

    /**
     * True once the host application has configured a default. Lets a
     * caller choose to degrade gracefully, or fail with a more specific
     * error of its own, instead of catching get()'s LogicException.
     */
    public static function isConfigured(): bool
    {
        return self::$currency !== null;
    }

    /**
     * Clears the configured default. Test-only: PHPUnit runs many tests
     * in one PHP process, and this class is deliberately plain static
     * state (not container-scoped), so a test that calls set() must
     * reset() in tearDown() to avoid leaking its choice of currency into
     * unrelated tests that run afterward in the same process.
     */
    public static function reset(): void
    {
        self::$currency = null;
    }
}
