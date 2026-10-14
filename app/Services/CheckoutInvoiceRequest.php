<?php

namespace App\Services;

use App\Enums\InvoiceRecipientType;

/**
 * An invoice the customer asked for, already VALIDATED against the merchant's rules by the HTTP layer (App\Services\InvoiceRequestChecker):
 * the billing snapshot that becomes one `order_invoice_details` row inside the placement transaction (order-context-design.md 9.3, 9.6).
 * `$email` NULL means "the order's own email"; `$countryRuleVersion` is the version of the country's rule set that accepted it (NULL when the
 * country has no rule and the generic one applied). An individual never carries a tax id, a registration number or a representative.
 */
final class CheckoutInvoiceRequest
{
    public function __construct(
        public readonly InvoiceRecipientType $recipientType,
        public readonly string $legalName,
        public readonly string $country,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $addressLine1,
        public readonly ?string $taxId = null,
        public readonly ?string $companyRegistrationNumber = null,
        public readonly ?string $representative = null,
        public readonly ?string $addressLine2 = null,
        public readonly ?string $email = null,
        public readonly ?string $countryRuleVersion = null,
    ) {
    }
}
