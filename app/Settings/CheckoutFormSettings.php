<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;

/**
 * THE ONE READER of the checkout form's settings (checkout form v2, section 6.6): the three optional choices, the terms and privacy
 * links, and the two region rules. EVERY key is read with ONE `getMany` call (the repository's per-request memo then serves every later
 * reader of any of them), so a checkout request and the page shell pay one settings query for all of it, however many of these values
 * they ask for (RN-1b's lesson). CheckoutRegionPolicy reads its two keys through this class for exactly that reason.
 *
 * DEFAULTS (site-settings-design.md section 5: the owner of a key owns its default):
 *  - `checkout.call_before_shipping_enabled`, `checkout.newsletter_enabled`: '1' = shown, absent (or anything else) = OFF;
 *  - `checkout.terms_enabled`: '0' = off, absent (or anything else) = ON (the EU default); the checkbox is shown only when it is on AND a
 *    terms URL is set (a sentence asking to accept terms nobody can open is worse than none);
 *  - `checkout.terms_url`, `checkout.privacy_url`: absent = none; `checkout.terms_version`: absent = none.
 */
final class CheckoutFormSettings
{
    public const CALL_BEFORE_SHIPPING_KEY = 'checkout.call_before_shipping_enabled';

    public const NEWSLETTER_KEY = 'checkout.newsletter_enabled';

    public const TERMS_ENABLED_KEY = 'checkout.terms_enabled';

    public const TERMS_URL_KEY = 'checkout.terms_url';

    public const TERMS_VERSION_KEY = 'checkout.terms_version';

    public const PRIVACY_URL_KEY = 'checkout.privacy_url';

    /** Every key this reader (and the region policy through it) needs, read together. */
    public const KEYS = [
        self::CALL_BEFORE_SHIPPING_KEY,
        self::NEWSLETTER_KEY,
        self::TERMS_ENABLED_KEY,
        self::TERMS_URL_KEY,
        self::TERMS_VERSION_KEY,
        self::PRIVACY_URL_KEY,
        CheckoutRegionPolicy::DEFAULT_KEY,
        CheckoutRegionPolicy::COUNTRIES_KEY,
    ];

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /**
     * All the raw stored values, key => string|null, in ONE query (then the memo).
     *
     * @return array<string, ?string>
     */
    public function raw(): array
    {
        return $this->settings->getMany(self::KEYS);
    }

    public function callBeforeShippingEnabled(): bool
    {
        return $this->raw()[self::CALL_BEFORE_SHIPPING_KEY] === '1';
    }

    public function newsletterEnabled(): bool
    {
        return $this->raw()[self::NEWSLETTER_KEY] === '1';
    }

    /** The merchant's switch: ON unless '0' is stored. */
    public function termsEnabled(): bool
    {
        return $this->raw()[self::TERMS_ENABLED_KEY] !== '0';
    }

    public function termsUrl(): ?string
    {
        return $this->filled(self::TERMS_URL_KEY);
    }

    public function termsVersion(): ?string
    {
        return $this->filled(self::TERMS_VERSION_KEY);
    }

    /** The version the page displays and the API compares with: the stored one, or a fixed word when the merchant never set one. */
    public function currentTermsVersion(): string
    {
        return $this->termsVersion() ?? 'unversioned';
    }

    public function privacyUrl(): ?string
    {
        return $this->filled(self::PRIVACY_URL_KEY);
    }

    /** Is the terms checkbox shown (and the acceptance required, and the version checked)? On AND a (safe) URL to read them at. */
    public function termsRequired(): bool
    {
        return $this->termsEnabled() && $this->safeTermsUrl() !== null;
    }

    private function filled(string $key): ?string
    {
        $value = $this->raw()[$key];

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * A link the merchant stored, as a value safe to put in an href: an http(s) URL, or a same-site path starting with a single `/`
     * (never `//`: that is a protocol-relative URL to another host). Anything else is not a link. Used by the settings page to refuse
     * what it would not store, and by the page to refuse what a hand-edited database row might hold.
     */
    public static function isSafeLink(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! str_contains($url, '\\');
        }

        $parts = parse_url($url);

        return $parts !== false
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ! empty($parts['host']);
    }

    /** The terms link for the page, or null when there is none or the stored value is not a safe link. */
    public function safeTermsUrl(): ?string
    {
        $url = $this->termsUrl();

        return $url !== null && self::isSafeLink($url) ? $url : null;
    }

    public function safePrivacyUrl(): ?string
    {
        $url = $this->privacyUrl();

        return $url !== null && self::isSafeLink($url) ? $url : null;
    }
}
