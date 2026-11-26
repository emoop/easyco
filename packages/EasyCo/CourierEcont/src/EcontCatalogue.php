<?php

namespace EasyCo\CourierEcont;

use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\CourierSettlement;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\PickupPointBatch;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Contracts\PickupPointCatalogue;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use Throwable;

/**
 * Econt's offices, automatic stations and settlements (courier-integration-design.md §7.2): `NomenclaturesService.getOffices.json`
 * and `getCities.json` with `{"countryCode": "BGR"}`. HTTP Basic is sent only when a user name and password are filled in.
 *
 * THE SHAPE IS NOT CONFIRMED against a live response (no recorded sample was available): this reads the shape Econt's public
 * documentation describes, and the fixture was built from that, not recorded. The assumptions, in one place:
 *   getOffices  root `offices`: [{ id, code, name, isAPS, isMPS, address: { city: { id, name, postCode }, fullAddress,
 *               location: { latitude, longitude } } }]
 *   reference   `code` (what the shipment API takes) -> reference; `id` -> external_id
 *   kind        `isAPS` true -> locker (`courier_type` = aps); everything else, `isMPS` included, -> office (`courier_type` = mps / office).
 *               (The design's §3.2 guessed `mps` -> locker; the prompt of this stage says only the automatic station is one. Unconfirmed.)
 *   settlement  address.city.name; the courier's own city id address.city.id -> courier_settlement_ref (the join key to settlements)
 *   coordinates location 0/0 or missing means "unknown"
 *   getCities   root `cities`: [{ id, name, postCode, regionName }]
 * An office that cannot be built is SKIPPED AND COUNTED. A list that is not a list throws INVALID_RESPONSE.
 */
final class EcontCatalogue implements PickupPointCatalogue
{
    public function __construct(private readonly CourierHttp $http) {}

    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch
    {
        $items = $this->list($credentials, $countryCode, 'Nomenclatures/NomenclaturesService.getOffices.json', 'offices', 'offices');

        $points = [];
        $malformed = 0;

        foreach ($items as $item) {
            $point = is_array($item) ? $this->map($item, $countryCode) : null;

            if ($point === null) {
                $malformed++;

                continue;
            }

            $points[] = $point;
        }

        return new PickupPointBatch($points, $malformed);
    }

    public function settlements(CourierCredentials $credentials, string $countryCode): array
    {
        $settlements = [];

        foreach ($this->list($credentials, $countryCode, 'Nomenclatures/NomenclaturesService.getCities.json', 'cities', 'cities') as $city) {
            $id = is_array($city) ? ($city['id'] ?? null) : null;
            $name = is_array($city) ? $this->text($city['name'] ?? null) : null;

            if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || $name === null) {
                continue;
            }

            try {
                $settlements[] = new CourierSettlement(EcontDescriptor::CODE, (string) $id, $name, $this->text($city['postCode'] ?? null), $this->text($city['regionName'] ?? null));
            } catch (InvalidCarrierDataException) {
                continue;
            }
        }

        return $settlements;
    }

    /** @return list<mixed> */
    private function list(CourierCredentials $credentials, string $countryCode, string $service, string $root, string $operation): array
    {
        if ($countryCode !== 'BG') {
            throw new CourierHttpException(CourierHttpException::INVALID_REQUEST);
        }

        $response = $this->http->postJson(
            EcontDescriptor::HOSTS[$credentials->environment()->value],
            EcontDescriptor::path($credentials->environment(), $service),
            ['countryCode' => 'BGR'],
            HttpCall::read(EcontDescriptor::CODE, $operation, CallKind::SYNC),
            EcontDescriptor::authHeaders($credentials),
        );

        return $this->items($response, $root);
    }

    /** @return list<mixed> */
    private function items(CourierResponse $response, string $root): array
    {
        if (in_array($response->status, [401, 403], true)) {
            throw new CourierHttpException(CourierHttpException::AUTH_FAILED, $response->status);
        }

        if (! $response->successful()) {
            throw new CourierHttpException(CourierHttpException::UNAVAILABLE, $response->status);
        }

        $json = $response->json();
        $items = is_array($json) ? ($json[$root] ?? null) : null;

        if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
            throw new CourierHttpException(CourierHttpException::INVALID_RESPONSE, $response->status);
        }

        return $items;
    }

    /** @param array<mixed> $item */
    private function map(array $item, string $countryCode): ?PickupPoint
    {
        $code = $item['code'] ?? null;
        $code = is_int($code) ? (string) $code : $code;

        if (! is_string($code) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $code) !== 1) {
            return null;
        }

        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $city = is_array($address['city'] ?? null) ? $address['city'] : [];

        $name = $this->text($item['name'] ?? null);
        $settlement = $this->text($city['name'] ?? null);
        $addressLine = $this->text($address['fullAddress'] ?? null) ?? $this->join([$address['street'] ?? null, $address['num'] ?? null]);

        if ($name === null || $settlement === null || $addressLine === null) {
            return null;
        }

        $cityId = $city['id'] ?? null;
        $cityId = is_int($cityId) || is_string($cityId) ? (string) $cityId : null;
        // An id that is not 1 to 18 digits is dropped, not the point: it stays listed, unmapped.
        $cityId = $cityId !== null && preg_match('/^\d{1,18}$/D', $cityId) === 1 ? $cityId : null;
        $externalId = $item['id'] ?? null;
        $externalId = is_int($externalId) || (is_string($externalId) && $externalId !== '') ? substr((string) $externalId, 0, 64) : null;

        $isAps = ($item['isAPS'] ?? false) === true;
        $isMps = ($item['isMPS'] ?? false) === true;
        $postcode = $this->text($city['postCode'] ?? null);
        [$latitude, $longitude] = $this->coordinates(is_array($address['location'] ?? null) ? $address['location'] : []);

        try {
            return new PickupPoint(
                carrierCode: EcontDescriptor::CODE,
                reference: $code,
                name: $name,
                countryCode: $countryCode,
                settlement: $settlement,
                addressLine: $addressLine,
                kind: $isAps ? PickupPointKind::LOCKER : PickupPointKind::OFFICE,
                postcode: $postcode !== null && strlen($postcode) <= 12 ? $postcode : null,
                latitude: $latitude,
                longitude: $longitude,
                courierType: $isAps ? 'aps' : ($isMps ? 'mps' : 'office'),
                externalId: $externalId,
                courierSettlementRef: $cityId,
            );
        } catch (InvalidCarrierDataException|Throwable) {
            return null;
        }
    }

    /** @param array<mixed> $location @return array{0: ?float, 1: ?float} */
    private function coordinates(array $location): array
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
            return [null, null];
        }

        $lat = round((float) $lat, 6);
        $lng = round((float) $lng, 6);

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : [null, null];
    }

    /** @param list<mixed> $parts */
    private function join(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(fn ($p) => $this->text(is_scalar($p) ? (string) $p : null), $parts)));

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
