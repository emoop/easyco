<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\ConnectionCheck;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierField;

/**
 * What an INSTALLED courier tells the application about itself (courier-integration-design.md §2.1, §5, §10.2). Bound as
 * `shipping.carrier.<code>.descriptor` (CarrierRegistration::descriptorKey()). Installed is not enabled: the merchant switches a courier on
 * in the «Куриери» page, and until then nothing here is called by any schedule, checkout or request.
 *
 * The descriptor reads no setting and calls no facade: the application hands it what it stored.
 */
interface CourierDescriptor
{
    public function code(): string;

    /** @return list<CourierField> the merchant's inputs, in display order */
    public function fields(): array;

    /**
     * The exact hosts this courier may be called on in $environment, given the stored plain values of that environment.
     * A value that is missing or not allowed gives no host for it (never a guess). Public lists and the API may differ.
     *
     * @param  array<string, string>  $plainValues  the non-secret field values of $environment
     * @return list<string>
     */
    public function hosts(CourierEnvironment $environment, array $plainValues): array;

    /** One harmless authenticated call. Closed error code on failure; never throws, never returns a body. */
    public function checkConnection(CourierHttp $http, CourierCredentials $credentials): ConnectionCheck;
}
