<?php

namespace App\Settings;

/**
 * What one country's invoice rule says (order-context-design.md section 9.3, stage C2-A): the labels the storefront shows and the
 * administrator reads, whether a company's tax id / registration number are required, and an optional format for the tax id.
 *
 * DATA, never code and never schema: a rule is what the merchant (and his accountant) typed into the settings page. The design asserts
 * no national format as fact. A label that is NULL falls back to the generic one (lang `checkout.invoice_labels.*`); a missing tax-id
 * requirement defaults to REQUIRED, the generic rule for a company; a missing pattern means NO format check at all.
 */
final class InvoiceCountryRule
{
    public function __construct(
        public readonly ?string $taxIdLabel = null,
        public readonly bool $taxIdRequiredForCompany = true,
        public readonly ?string $taxIdPattern = null,
        public readonly ?string $registrationNumberLabel = null,
        public readonly bool $registrationNumberRequired = false,
        public readonly ?string $representativeLabel = null,
    ) {
    }

    /** The generic rule: no country-specific entry exists. */
    public static function generic(): self
    {
        return new self();
    }

    public function taxIdLabelOrDefault(): string
    {
        return $this->taxIdLabel ?? (string) __('checkout.invoice_labels.tax_id');
    }

    public function registrationNumberLabelOrDefault(): string
    {
        return $this->registrationNumberLabel ?? (string) __('checkout.invoice_labels.registration_number');
    }

    public function representativeLabelOrDefault(): string
    {
        return $this->representativeLabel ?? (string) __('checkout.invoice_labels.representative');
    }

    /** Does a value fit the configured tax-id format? No pattern = no check; an unusable pattern (it cannot run) = no check. */
    public function taxIdMatches(string $value): bool
    {
        if ($this->taxIdPattern === null) {
            return true;
        }

        $result = @preg_match(InvoiceSettings::delimited($this->taxIdPattern), $value);

        return $result !== 0;
    }
}
