<?php

namespace App\Services;

/**
 * Everything the order page shows about an order's CONTEXT (order-context-design.md): who and from where, what the
 * customer agreed to and asked for, the invoice request, the origin. Every field is nullable, and null means
 * "NOT RECORDED" — never "no": a boolean is true or false only when something recorded it. The page renders a null
 * as "n/a".
 */
final class OrderContextView
{
    public function __construct(
        public readonly ?string $visitorId = null,
        public readonly ?bool $termsAccepted = null,
        public readonly ?bool $confirmationRequested = null,
        public readonly ?bool $callBeforeShipping = null,
        public readonly ?string $invoiceCompanyName = null,
        public readonly ?string $invoiceVatNumber = null,
        public readonly ?string $invoiceBillingAddress = null,
        public readonly ?string $originSourceType = null,
        public readonly ?string $originCampaign = null,
        public readonly ?string $originLandingPage = null,
        public readonly ?string $originReferrer = null,
        // What the customer ticked (checkout form v2, C1d-B): when and which terms, whether they asked to be newsletter-subscribed and the
        // exact sentence they ticked. Null = not asked / placed before the field existed.
        public readonly ?\DateTimeInterface $termsAcceptedAt = null,
        public readonly ?string $termsVersion = null,
        public readonly ?bool $marketingConsent = null,
        public readonly ?string $marketingConsentText = null,
        // THE ORIGIN, read from `order_attributions` (order-context-design.md 11.1, stage C2-A). Every value is a DISPLAY-READY string: the
        // reader has already put the enums through their labels. NULL = no such fact. THE FULL CLIENT ADDRESS IS DELIBERATELY NOT HERE:
        // it is read by OrderContextReader::fullClientAddress(), a separate method that answers only for ORDER_MANAGE (section 7.1). What
        // this object may carry of the address is the date it was anonymised and the anonymised network, which are not personal data.
        public readonly ?string $originDeviceType = null,
        public readonly ?string $originConsent = null,
        public readonly ?string $originReason = null,
        public readonly ?string $accountId = null,
        public readonly ?bool $attributionRecorded = null,
        public readonly ?\DateTimeInterface $ipAnonymisedAt = null,
        public readonly ?string $ipNetwork = null,
        // THE INVOICE REQUEST, read from `order_invoice_details` (section 11.4). `invoiceCompanyName` / `invoiceVatNumber` /
        // `invoiceBillingAddress` above are its legal name, tax id and billing address; these are the rest, with the labels the merchant configured.
        public readonly ?bool $invoiceRequested = null,
        public readonly ?string $invoiceRecipientType = null,
        public readonly ?string $invoiceTaxIdLabel = null,
        public readonly ?string $invoiceRegistrationNumber = null,
        public readonly ?string $invoiceRegistrationLabel = null,
        public readonly ?string $invoiceRepresentative = null,
        public readonly ?string $invoiceRepresentativeLabel = null,
        public readonly ?string $invoiceEmail = null,
        public readonly ?string $invoiceRuleVersion = null,
        public readonly ?\DateTimeInterface $invoiceRequestedAt = null,
    ) {
    }

    /** Nothing recorded: what every order is today, because the order-context stages are not built. */
    public static function notRecorded(): self
    {
        return new self();
    }
}
