<?php

namespace EasyCo\CourierBoxNow;

use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\PickupPointBatch;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Contracts\PickupPointCatalogue;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * BOX NOW's parcel lockers, from the public locker list (courier-integration-design.md §7.1: `GET /v1/apms_bg-BG.json` on the
 * list host of the credentials' environment; no credentials are sent). Every item is a LOCKER.
 *
 * FIELD NAMES ARE NOT CONFIRMED against a live response (see the COUR-1a report): this reads the shape BOX NOW's partner
 * documentation and its public location files are known for, and the test fixture was built from that shape, not recorded
 * live. The assumptions, in one place:
 *   root            `{"data": [...]}` or a bare list (both accepted)
 *   id              `id` (string or int)                      -> reference (the locker id the delivery-request API takes)
 *   type            `type`; only "apm" is a locker; any other explicit type (e.g. a pseudo "any-apm") is skipped as malformed
 *   name            `title`, else `name`
 *   address         `addressLine1` (required); `addressLine2` usually holds postcode and city
 *   postcode        `postalCode`, else the digits of `addressLine2`
 *   settlement      `city` / `town` if present, else the non-digit part of `addressLine2`; none -> the item is skipped
 *   coordinates     `lat`, `lng` (string or number); an invalid or half pair is dropped, the locker is kept
 * A locker that cannot be built is SKIPPED AND COUNTED, never fatal. A response that is not a list throws INVALID_RESPONSE.
 */
final class BoxNowCatalogue implements PickupPointCatalogue
{
    private const PATH = '/v1/apms_bg-BG.json';

    public function __construct(private readonly CourierHttp $http) {}

    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch
    {
        if ($countryCode !== 'BG') {
            throw new CourierHttpException(CourierHttpException::INVALID_REQUEST);
        }

        $response = $this->http->get(
            BoxNowDescriptor::LIST_HOSTS[$credentials->environment()->value],
            self::PATH,
            [],
            HttpCall::read(BoxNowDescriptor::CODE, 'locker_list', CallKind::SYNC),
        );

        if (! $response->successful()) {
            throw new CourierHttpException(CourierHttpException::UNAVAILABLE, $response->status);
        }

        $json = $response->json();
        $items = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;

        if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
            throw new CourierHttpException(CourierHttpException::INVALID_RESPONSE, $response->status);
        }

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
        return [];
    }

    /** @param array<mixed> $item */
    private function map(array $item, string $countryCode): ?PickupPoint
    {
        $type = $item['type'] ?? null;

        if ($type !== null && $type !== 'apm') {
            return null;
        }

        $id = $item['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $reference = (string) $id;

        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $reference) !== 1) {
            return null;
        }

        $name = $this->text($item['title'] ?? null) ?? $this->text($item['name'] ?? null);
        $address = $this->text($item['addressLine1'] ?? null);
        $line2 = $this->text($item['addressLine2'] ?? null);
        $postcode = $this->text($item['postalCode'] ?? null);

        if ($postcode === null && $line2 !== null && preg_match('/\b(\d{4,6})\b/', $line2, $m) === 1) {
            $postcode = $m[1];
        }

        $settlement = $this->text($item['city'] ?? null) ?? $this->text($item['town'] ?? null);

        if ($settlement === null && $line2 !== null) {
            $settlement = $this->text(trim(preg_replace('/[\d,]+/u', ' ', $line2) ?? '', " \t,-"));
        }

        if ($name === null || $address === null || $settlement === null) {
            return null;
        }

        [$latitude, $longitude] = $this->coordinates($item);

        try {
            return new PickupPoint(
                carrierCode: BoxNowDescriptor::CODE,
                reference: $reference,
                name: $name,
                countryCode: $countryCode,
                settlement: $settlement,
                addressLine: $address,
                kind: PickupPointKind::LOCKER,
                postcode: $postcode !== null && strlen($postcode) <= 12 ? $postcode : null,
                latitude: $latitude,
                longitude: $longitude,
                courierType: 'apm',
            );
        } catch (InvalidCarrierDataException) {
            return null;
        }
    }

    /** @param array<mixed> $item @return array{0: ?float, 1: ?float} */
    private function coordinates(array $item): array
    {
        $lat = $item['lat'] ?? null;
        $lng = $item['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [null, null];
        }

        $lat = round((float) $lat, 6);
        $lng = round((float) $lng, 6);

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : [null, null];
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
