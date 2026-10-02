<?php

namespace EasyCo\Shipping\Carrier;

/**
 * Why a rate or pickup-point call produced no answer (shipping-domain-design.md §6,
 * failure tolerance). A closed list of reasons, never free text, so a reason can
 * be logged and shown without ever carrying a provider's message (which may echo
 * an address back).
 */
enum CarrierUnavailableReason: string
{
    /** The provider threw. */
    case PROVIDER_ERROR = 'provider_error';

    /** The provider answered, but after its time budget: the answer is discarded. */
    case TIMED_OUT = 'timed_out';

    /** The provider answered with something that is not a valid answer (a wrong type, another currency). */
    case INVALID_RESPONSE = 'invalid_response';

    /** The carrier code is unknown, or the carrier does not offer this capability (a configuration fault). */
    case NOT_CONFIGURED = 'not_configured';
}
