<?php

namespace App\Settings\Exceptions;

use RuntimeException;

/**
 * The store's country (`site.country`) is unset, or holds something that is
 * not an ISO 3166-1 alpha-2 code. Thrown by StoreCountry::current() — never
 * guessed around: a default country would quietly decide which zone a pickup
 * point falls in (CLAUDE.md rule 8).
 */
final class StoreCountryNotConfiguredException extends RuntimeException
{
    public static function unset(): self
    {
        return new self('The store country (site.country) is not configured. Set it in Settings before anything depends on it.');
    }

    public static function invalid(string $value): self
    {
        return new self("The store country (site.country) holds \"{$value}\", which is not an uppercase ISO 3166-1 alpha-2 code. Set it again in Settings.");
    }
}
