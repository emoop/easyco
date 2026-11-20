<?php

namespace App\Privacy;

use RuntimeException;

/** PRIVACY_HASH_KEY is missing where a silent fallback would be wrong (any environment except local / testing). Shown to the admin as it is. */
final class PrivacyNotConfigured extends RuntimeException
{
    public static function missingHashKey(): self
    {
        return new self('PRIVACY_HASH_KEY is not set. Privacy requests are refused until it is: generate one (64 hex characters), put it into the environment next to APP_KEY, and never change it afterwards.');
    }
}
