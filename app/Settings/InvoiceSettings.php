<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * THE ONE READER of the invoice-request settings (order-context-design.md section 9.3, stage C2-A): whether the shop offers an invoice
 * at all, and the per-country rules. Three keys, all read through the checkout form's ONE `getMany` (CheckoutFormSettings::KEYS lists
 * them), so a page shell and a checkout request pay no query for them:
 *
 *  - `invoice.enabled`: '0' = off, absent (or anything else) = ON (being unable to ask for an invoice is a legal problem in the EU);
 *  - `invoice.country_rules`: a JSON OBJECT {ISO2: {tax_id_label, tax_id_required_for_company, tax_id_pattern, registration_number_label,
 *    registration_number_required, representative_label}}; absent = {} (no country has a rule: the generic rule applies everywhere);
 *  - `invoice.country_rules_version`: a counter the settings page bumps by itself every time the rules CHANGE (never typed by a person); the
 *    version that accepted an invoice is recorded on its row (`order_invoice_details.country_rule_version`).
 *
 * A MALFORMED stored rules value (not JSON, not an object, an unknown country, a pattern that does not compile) THROWS
 * InvalidArgumentException (CLAUDE.md rule 8: fail loud, no silent guessing): the settings page cannot save such a value, because it builds
 * what it stores with normalizeRows() / encode(), the same rules the reader applies.
 *
 * No country is pre-filled and no format is asserted as fact; every pattern field carries the hint "confirm the format with your accountant".
 */
final class InvoiceSettings
{
    public const ENABLED_KEY = 'invoice.enabled';

    public const RULES_KEY = 'invoice.country_rules';

    public const RULES_VERSION_KEY = 'invoice.country_rules_version';

    public const KEYS = [
        self::ENABLED_KEY,
        self::RULES_KEY,
        self::RULES_VERSION_KEY,
    ];

    public const MAX_LABEL_LENGTH = 64;

    public const MAX_PATTERN_LENGTH = 200;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /** @return array<string, ?string> */
    private function raw(): array
    {
        return $this->settings->getMany(CheckoutFormSettings::KEYS);
    }

    /** The merchant's switch: ON unless '0' is stored. */
    public function enabled(): bool
    {
        return $this->raw()[self::ENABLED_KEY] !== '0';
    }

    /** The version of the rule set as stored, or null when no rules were ever saved. */
    public function version(): ?string
    {
        $value = $this->raw()[self::RULES_VERSION_KEY];

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * Every configured rule, by upper-case ISO code.
     *
     * @return array<string, InvoiceCountryRule>
     *
     * @throws InvalidArgumentException a malformed stored value
     */
    public function rules(): array
    {
        $raw = $this->raw()[self::RULES_KEY];

        return $raw === null || trim($raw) === '' ? [] : self::parse($raw);
    }

    public function hasRuleFor(string $country): bool
    {
        return isset($this->rules()[strtoupper(trim($country))]);
    }

    /** The rule for a country, or the generic one when the merchant configured none. */
    public function ruleFor(string $country): InvoiceCountryRule
    {
        return $this->rules()[strtoupper(trim($country))] ?? InvoiceCountryRule::generic();
    }

    /**
     * The rules as the checkout page needs them (labels and requirements, NEVER a pattern: the server is the only judge of a format),
     * written into a data attribute. An empty list is `{}`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forPage(): array
    {
        $out = [];

        foreach ($this->rules() as $country => $rule) {
            $out[$country] = [
                'tax_id_label' => $rule->taxIdLabel,
                'tax_id_required_for_company' => $rule->taxIdRequiredForCompany,
                'registration_number_label' => $rule->registrationNumberLabel,
                'registration_number_required' => $rule->registrationNumberRequired,
                'representative_label' => $rule->representativeLabel,
            ];
        }

        return $out;
    }

    // ---- parsing and validation, shared by the reader and the settings page ---------------------------------------------------

    /**
     * @return array<string, InvoiceCountryRule>
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('it is not a JSON object of country => rule');
        }

        $rows = [];

        foreach ($decoded as $country => $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('a rule is not an object');
            }

            $rows[] = ['country' => (string) $country] + $rule;
        }

        return self::normalizeRows($rows);
    }

    /**
     * THE ONE VALIDATION of the rules list: every country a KNOWN ISO code (upper-cased here) at most once, a label plain text of at most
     * 64 characters, a pattern that COMPILES and is at most 200 characters, the two requirements booleans. A blank label or pattern is "none".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, InvoiceCountryRule>
     *
     * @throws InvalidArgumentException with the reason
     */
    public static function normalizeRows(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $country = is_string($row['country'] ?? null) ? strtoupper(trim($row['country'])) : '';

            if (! CountryNames::isKnownCode($country)) {
                throw new InvalidArgumentException('a country is not a known ISO code');
            }

            if (isset($map[$country])) {
                throw new InvalidArgumentException('a country appears more than once');
            }

            $pattern = self::optionalText($row['tax_id_pattern'] ?? null, self::MAX_PATTERN_LENGTH, 'a pattern');

            if ($pattern !== null && @preg_match(self::delimited($pattern), '') === false) {
                throw new InvalidArgumentException('a pattern is not a valid regular expression');
            }

            $map[$country] = new InvoiceCountryRule(
                taxIdLabel: self::optionalText($row['tax_id_label'] ?? null, self::MAX_LABEL_LENGTH, 'a label'),
                taxIdRequiredForCompany: self::flag($row['tax_id_required_for_company'] ?? true),
                taxIdPattern: $pattern,
                registrationNumberLabel: self::optionalText($row['registration_number_label'] ?? null, self::MAX_LABEL_LENGTH, 'a label'),
                registrationNumberRequired: self::flag($row['registration_number_required'] ?? false),
                representativeLabel: self::optionalText($row['representative_label'] ?? null, self::MAX_LABEL_LENGTH, 'a label'),
            );
        }

        return $map;
    }

    /**
     * What is stored: a JSON object keyed by country, only the values that carry information (a blank label or pattern is left out), the two
     * requirements always written explicitly.
     *
     * @param  array<string, InvoiceCountryRule>  $rules
     */
    public static function encode(array $rules): string
    {
        $out = [];

        foreach ($rules as $country => $rule) {
            $entry = [];

            foreach (['tax_id_label' => $rule->taxIdLabel, 'tax_id_pattern' => $rule->taxIdPattern, 'registration_number_label' => $rule->registrationNumberLabel, 'representative_label' => $rule->representativeLabel] as $key => $value) {
                if ($value !== null) {
                    $entry[$key] = $value;
                }
            }

            $entry['tax_id_required_for_company'] = $rule->taxIdRequiredForCompany;
            $entry['registration_number_required'] = $rule->registrationNumberRequired;
            $out[$country] = $entry;
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /** A pattern as a full PCRE expression: the merchant types the body only; `~` is the delimiter (escaped inside), and the match is Unicode. */
    public static function delimited(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }

    private static function optionalText(mixed $value, int $max, string $what): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException($what.' is not text');
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) !== 0) {
            throw new InvalidArgumentException($what.' is too long or holds a control character');
        }

        return $value;
    }

    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
