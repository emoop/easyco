<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierSettlement;
use EasyCo\Shipping\Carrier\PickupPointBatch;
use EasyCo\Shipping\Exceptions\CourierHttpException;

/**
 * Every office and locker a courier lists, for the NIGHTLY SYNC (courier-integration-design.md §2.2, §3.4). Bound as
 * `shipping.carrier.<code>.pickup`. It REPLACES the live per-settlement PickupPointProvider (which had no production
 * implementation): the checkout reads only the local table and never calls a courier.
 *
 * An implementation fetches ONLY through CourierHttp, validates each item into a PickupPoint, skips and counts a malformed
 * one (never throws for it), and throws CourierHttpException when the list as a whole could not be had. No credentials or
 * response content in an exception message.
 */
interface PickupPointCatalogue
{
    /** @throws CourierHttpException */
    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch;

    /**
     * The courier's settlements (Speedy sites, Econt cities); BOX NOW returns an empty list.
     *
     * @return list<CourierSettlement>
     */
    public function settlements(CourierCredentials $credentials, string $countryCode): array;
}
