<?php

namespace App\Privacy;

use App\Services\PhoneNumbers;
use App\Settings\StoreCountry;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\Log;

/**
 * THE ONE keyed hash of a person's identifiers (acc-4-gdpr-design.md §3): HMAC-SHA256 with `privacy.hash_key`, hex. The input is normalised first, so
 * the same person always gives the same hash: an email by IdentityKeys::email() (trimmed, lower-cased); a phone by PhoneNumbers::e164() (a number the
 * library cannot read has no hash: null). Each kind is hashed under its own prefix, so an email and a phone can never collide.
 *
 * NO SILENT FALLBACK where it matters: without a key only `local` and `testing` derive a development key from APP_KEY (and say so once in the log);
 * every other environment refuses with PrivacyNotConfigured.
 */
final class PrivacyHash
{
    private static bool $warned = false;

    public function __construct(
        private readonly StoreCountry $country,
    ) {
    }

    /** The email's hash, or null for a blank one. @throws PrivacyNotConfigured */
    public function email(?string $email): ?string
    {
        $key = IdentityKeys::email($email);

        return $key === null ? null : $this->hmac('email', $key);
    }

    /** The phone's hash, or null when the number cannot be read. @throws PrivacyNotConfigured */
    public function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $e164 = PhoneNumbers::e164($phone, $this->country->currentOrNull());

        return $e164 === null ? null : $this->hmac('phone', $e164);
    }

    private function hmac(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.'|'.$value, $this->key());
    }

    private function key(): string
    {
        $configured = config('privacy.hash_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw PrivacyNotConfigured::missingHashKey();
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning('privacy.hash_key_missing: a development key derived from APP_KEY is used (PRIVACY_HASH_KEY is not set).');
        }

        return hash('sha256', 'privacy-dev-key|'.(string) config('app.key'));
    }
}
