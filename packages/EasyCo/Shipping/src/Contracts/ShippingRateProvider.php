<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;

/**
 * A live rate from a carrier (shipping-domain-design.md §6). Bound in the
 * container as `shipping.carrier.<code>.rate`; a carrier may implement only
 * some of the three provider contracts.
 *
 * OBLIGATIONS OF EVERY IMPLEMENTATION:
 *  - Be CHEAP and SIDE-EFFECT FREE: a rate is asked for before the order exists,
 *    repeatedly, while the customer is still deciding. quote() creates nothing
 *    with the carrier and charges nothing.
 *  - HONOUR $budget: the whole call, connect and read together, must not outlast
 *    it — use it as the timeout of your own HTTP client. PHP cannot interrupt
 *    arbitrary code, so nothing else will stop a slow call; an answer that
 *    arrives late is discarded by the caller and counted as an overrun.
 *  - Never hold a database transaction open across the call
 *    (checkout-orchestration-performance-note.md §2); an implementation should
 *    not touch the database at all.
 *  - Throwing is allowed and expected on failure: CarrierCallGuard turns any
 *    exception into an explicit "unavailable". Do not put customer data in an
 *    exception message.
 *  - Return quotes in $context->currency only.
 */
interface ShippingRateProvider
{
    /** @return list<ShippingQuote> empty means this carrier will not carry it */
    public function quote(ShippingContext $context, CallBudget $budget): array;
}
