<?php

namespace App\Services;

use App\Enums\InvoiceRecipientType;
use App\Services\Exceptions\InvoiceDetailsInvalidException;
use App\Settings\InvoiceSettings;

/**
 * Judges an invoice request that already passed the SHAPE rules (CheckoutController::validationRules) against the merchant's per-country
 * rules (order-context-design.md 9.3), and turns it into the snapshot checkout writes. The rules, in the order they run:
 *
 *  1. an INDIVIDUAL carries no tax id (no national id is collected for a private person), no registration number and no representative:
 *     refused (`invoice_details_invalid`), never silently dropped;
 *  2. a COMPANY's tax id is required unless the country's rule says otherwise (the generic rule, with no entry, requires it with NO format
 *     check); a CONFIGURED pattern is enforced and a mismatch refused, quoting the configured label; an absent pattern is not checked;
 *  3. a company's registration number is required only when the country's rule says so.
 *
 * Every refusal names the field and quotes the label the merchant configured for that country (the generic label when none). The version of
 * the rule set is recorded only when the country HAS a rule: it names the data that accepted the row.
 */
final class InvoiceRequestChecker
{
    public function __construct(
        private readonly InvoiceSettings $settings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $invoice  the validated `invoice` block
     *
     * @throws InvoiceDetailsInvalidException
     */
    public function check(array $invoice): CheckoutInvoiceRequest
    {
        $type = InvoiceRecipientType::from((string) $invoice['recipient_type']);
        $country = strtoupper(trim((string) $invoice['country']));
        $rule = $this->settings->ruleFor($country);

        $taxId = $this->text($invoice['tax_id'] ?? null);
        $registration = $this->text($invoice['company_registration_number'] ?? null);
        $representative = $this->text($invoice['representative'] ?? null);

        if ($type === InvoiceRecipientType::INDIVIDUAL) {
            foreach (['tax_id' => [$taxId, $rule->taxIdLabelOrDefault()], 'company_registration_number' => [$registration, $rule->registrationNumberLabelOrDefault()], 'representative' => [$representative, $rule->representativeLabelOrDefault()]] as $field => [$value, $label]) {
                if ($value !== null) {
                    throw new InvoiceDetailsInvalidException('invoice.'.$field, (string) __('checkout.invoice_individual_field', ['label' => $label]));
                }
            }
        } else {
            if ($taxId === null && $rule->taxIdRequiredForCompany) {
                throw new InvoiceDetailsInvalidException('invoice.tax_id', (string) __('checkout.invoice_field_required', ['label' => $rule->taxIdLabelOrDefault()]));
            }

            if ($taxId !== null && ! $rule->taxIdMatches($taxId)) {
                throw new InvoiceDetailsInvalidException('invoice.tax_id', (string) __('checkout.invoice_field_format', ['label' => $rule->taxIdLabelOrDefault()]));
            }

            if ($registration === null && $rule->registrationNumberRequired) {
                throw new InvoiceDetailsInvalidException('invoice.company_registration_number', (string) __('checkout.invoice_field_required', ['label' => $rule->registrationNumberLabelOrDefault()]));
            }
        }

        return new CheckoutInvoiceRequest(
            recipientType: $type,
            legalName: trim((string) $invoice['legal_name']),
            country: $country,
            city: trim((string) $invoice['city']),
            postalCode: trim((string) $invoice['postal_code']),
            addressLine1: trim((string) $invoice['address_line_1']),
            taxId: $taxId,
            companyRegistrationNumber: $registration,
            representative: $representative,
            addressLine2: $this->text($invoice['address_line_2'] ?? null),
            email: $this->text($invoice['email'] ?? null),
            countryRuleVersion: $this->settings->hasRuleFor($country) ? $this->settings->version() : null,
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
