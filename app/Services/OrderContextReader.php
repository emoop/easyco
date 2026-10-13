<?php

namespace App\Services;

use EasyCo\Order\Persistence\Eloquent\OrderModel;

/**
 * THE ONE SOURCE of an order's context for the admin pages (order-context-design.md). What the CUSTOMER TICKED at checkout is on the
 * order's own row since checkout form v2 (stage C1d-B: the terms proof, the call-before-shipping and newsletter answers and the
 * consent sentence), so it is read from the record the page already loaded: NO query. Everything else (the origin, the IP, the
 * invoice) is still "not recorded" until the order-context data stages are built, and they will extend this class and nothing else.
 */
class OrderContextReader
{
    public function forOrder(string $orderId): OrderContextView
    {
        return OrderContextView::notRecorded();
    }

    /**
     * The context for an order the page already loaded: forOrder() (the seam the origin / IP / invoice stages will fill) plus what the
     * customer ticked, read from the row itself (no query). A value the row holds wins; a NULL on the row leaves what forOrder() said.
     */
    public function forRecord(OrderModel $record): OrderContextView
    {
        $base = $this->forOrder((string) $record->id);
        $accepted = $record->terms_accepted_at;

        return new OrderContextView(
            customerIp: $base->customerIp,
            visitorId: $base->visitorId,
            termsAccepted: $accepted === null ? $base->termsAccepted : true,
            confirmationRequested: $record->confirmation_requested === null ? $base->confirmationRequested : (bool) $record->confirmation_requested,
            callBeforeShipping: $record->call_before_shipping === null ? $base->callBeforeShipping : (bool) $record->call_before_shipping,
            invoiceCompanyName: $base->invoiceCompanyName,
            invoiceVatNumber: $base->invoiceVatNumber,
            invoiceBillingAddress: $base->invoiceBillingAddress,
            originSourceType: $base->originSourceType,
            originCampaign: $base->originCampaign,
            originLandingPage: $base->originLandingPage,
            originReferrer: $base->originReferrer,
            termsAcceptedAt: $accepted ?? $base->termsAcceptedAt,
            termsVersion: $record->terms_version ?? $base->termsVersion,
            marketingConsent: $record->marketing_consent === null ? $base->marketingConsent : (bool) $record->marketing_consent,
            marketingConsentText: $record->marketing_consent_text ?? $base->marketingConsentText,
        );
    }
}
