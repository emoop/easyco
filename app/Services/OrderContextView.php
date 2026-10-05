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
        public readonly ?string $customerIp = null,
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
    ) {
    }

    /** Nothing recorded: what every order is today, because the order-context stages are not built. */
    public static function notRecorded(): self
    {
        return new self();
    }
}
