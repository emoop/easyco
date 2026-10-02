<?php

namespace App\Settings\Exceptions;

use RuntimeException;

/**
 * ext-intl's ICU region data (the CLDR "Countries" table) could not be read,
 * neither for the requested locale nor for `en`. The country list cannot be
 * built; the PHP intl installation is incomplete.
 */
final class CountryDataUnavailableException extends RuntimeException
{
    public static function for(string $locale, string $bundleName): self
    {
        return new self("ICU region data is unavailable: no \"Countries\" table in the \"{$bundleName}\" bundle for locale \"{$locale}\" or for \"en\". Check that PHP's intl extension ships its locale data.");
    }
}
