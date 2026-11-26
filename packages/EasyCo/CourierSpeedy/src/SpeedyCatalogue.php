<?php

namespace EasyCo\CourierSpeedy;

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
 * Speedy's offices, automats and sites (courier-integration-design.md §7.3). Every request is a POST with the credentials and
 * `"language": "BG"` in the JSON body (see SpeedyDescriptor), so names come in Cyrillic like the postcode backbone:
 *   offices   `POST /v1/location/office` with `countryId: 100`
 *   sites     `POST /v1/location/site/csv/100`: the FULL list as a UTF-8 CSV. `location/site` is a search (every documented example
 *             passes a `name` or `postCode`), so it is not used for the full list.
 *
 * D (documented in Speedy's API reference, https://services.speedy.bg/api/docs/, read 2026-11-26), confirmed live 2026-11-26
 * (P: the owner's sync on dev with a production account: 1,413 points, 0 skipped, 0 without a settlement):
 *   FindOfficeResponse  root `offices`, plus an optional `error` object (an error can come with HTTP 200: it is refused, its text never read)
 *   Office              `id`, `name`, `nameEn`, `siteId`, `type` (`OFFICE` / `APT`), `address`, `pickUpAllowed`, `dropOffAllowed`,
 *                       `cardPaymentAllowed`, `cashPaymentAllowed`, `palletOffice`, `maxParcelWeight`, `validFrom`, `validTo`
 *   Address             `siteId`, `siteName`, `postCode`, `x` (longitude), `y` (latitude), `fullAddressString`, `siteAddressString`,
 *                       `localAddressString`
 *   site CSV columns    `id, countryId, mainSiteId, type, typeEn, name, nameEn, municipality, municipalityEn, region, regionEn, postCode,
 *                       addressNomenclature, x, y, servingDays, servingOfficeId, servingHubOfficeId`. Read BY THE HEADER'S NAMES, never by
 *                       position; a UTF-8 BOM on the first name is dropped; a missing `id` or `name` column is INVALID_RESPONSE.
 *                       Used: `id` -> reference, `name`, `postCode`, `region` -> region name. `municipality` is left out for now.
 *   office id           what `recipient.pickupOfficeId` takes (D, api_examples.php); `siteId` is the join key to the settlements
 * KEPT, NOT FILTERED ON: `pickUpAllowed`, `cardPaymentAllowed`, `cashPaymentAllowed` (booleans) and `validTo` (the string as given) go to
 * the point's `features`. An office that is not `pickUpAllowed` must not be OFFERED to a customer: that is a checkout rule (COUR-2a).
 * An office that cannot be built is SKIPPED AND COUNTED. A list that is not a list throws INVALID_RESPONSE.
 */
final class SpeedyCatalogue implements PickupPointCatalogue
{
    public function __construct(private readonly CourierHttp $http) {}

    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch
    {
        $points = [];
        $malformed = 0;

        foreach ($this->list($credentials, $countryCode, '/v1/location/office', 'offices', 'office_list') as $item) {
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
        if ($countryCode !== 'BG') {
            throw new CourierHttpException(CourierHttpException::INVALID_REQUEST);
        }

        $body = SpeedyDescriptor::authBody($credentials) ?? throw new CourierHttpException(CourierHttpException::NOT_CONFIGURED);

        // The raw text is read from CourierResponse::$body: the same allowlisted client, host and call guard as every other call.
        $response = $this->http->postJson(
            SpeedyDescriptor::HOST,
            '/v1/location/site/csv/'.SpeedyDescriptor::COUNTRY_ID,
            $body,
            HttpCall::read(SpeedyDescriptor::CODE, 'site_csv', CallKind::SYNC),
            ['Accept' => 'text/csv, application/json'],
        );

        $rows = $this->csv($response);
        $settlements = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? '';
            $name = $this->text($row['name'] ?? null);

            if (preg_match('/^\d{1,18}$/D', $id) !== 1 || $name === null) {
                continue;
            }

            try {
                $settlements[] = new CourierSettlement(SpeedyDescriptor::CODE, $id, $name, $this->text($row['postCode'] ?? null), $this->text($row['region'] ?? null));
            } catch (InvalidCarrierDataException) {
                continue;
            }
        }

        return $settlements;
    }

    /**
     * The site CSV as rows keyed by the header's column names.
     *
     * @return list<array<string, string>>
     */
    private function csv(CourierResponse $response): array
    {
        if (in_array($response->status, [401, 403], true)) {
            throw new CourierHttpException(CourierHttpException::AUTH_FAILED, $response->status);
        }

        if (! $response->successful()) {
            throw new CourierHttpException(CourierHttpException::UNAVAILABLE, $response->status);
        }

        $text = ltrim($response->body, "\xEF\xBB\xBF");

        if (str_starts_with(ltrim($text), '{')) {
            // JSON instead of CSV: an `error` object (the text is never read), or something that is not the list.
            $json = $response->json();

            throw is_array($json) && isset($json['error']) ? SpeedyDescriptor::errorFrom($json['error'], $response->status) : new CourierHttpException(CourierHttpException::INVALID_RESPONSE, $response->status);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);

        $first = fgets($handle);
        rewind($handle);
        $delimiter = is_string($first) && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        $columns = is_array($header) ? array_map(static fn ($h): string => trim((string) $h), $header) : [];

        if (! in_array('id', $columns, true) || ! in_array('name', $columns, true)) {
            fclose($handle);

            throw new CourierHttpException(CourierHttpException::INVALID_RESPONSE, $response->status);
        }

        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            $row = [];

            foreach ($columns as $i => $column) {
                $row[$column] = trim((string) ($line[$i] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<mixed> */
    private function list(CourierCredentials $credentials, string $countryCode, string $path, string $root, string $operation): array
    {
        if ($countryCode !== 'BG') {
            throw new CourierHttpException(CourierHttpException::INVALID_REQUEST);
        }

        $body = SpeedyDescriptor::authBody($credentials) ?? throw new CourierHttpException(CourierHttpException::NOT_CONFIGURED);

        $response = $this->http->postJson(
            SpeedyDescriptor::HOST,
            $path,
            $body + ['countryId' => SpeedyDescriptor::COUNTRY_ID],
            HttpCall::read(SpeedyDescriptor::CODE, $operation, CallKind::SYNC),
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

        if (is_array($json) && isset($json['error'])) {
            // Only the integer code is read, never the text: it may echo the request (SpeedyDescriptor::errorFrom).
            throw SpeedyDescriptor::errorFrom($json['error'], $response->status);
        }

        $items = is_array($json) ? ($json[$root] ?? null) : null;

        if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
            throw new CourierHttpException(CourierHttpException::INVALID_RESPONSE, $response->status);
        }

        return $items;
    }

    /** @param array<mixed> $item */
    private function map(array $item, string $countryCode): ?PickupPoint
    {
        $id = $item['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $name = $this->text($item['name'] ?? null);
        $settlement = $this->text($address['siteName'] ?? null);
        $addressLine = $this->text($address['fullAddressString'] ?? null) ?? $this->text($address['localAddressString'] ?? null);
        $type = $this->text($item['type'] ?? null);
        $siteId = $item['siteId'] ?? ($address['siteId'] ?? null);
        $siteId = is_int($siteId) || is_string($siteId) ? (string) $siteId : null;
        // An id that is not 1 to 18 digits is dropped, not the point: it stays listed, unmapped.
        $siteId = $siteId !== null && preg_match('/^\d{1,18}$/D', $siteId) === 1 ? $siteId : null;
        $postcode = $this->text($address['postCode'] ?? null);

        if ($name === null || $settlement === null || $addressLine === null || ! in_array($type, ['OFFICE', 'APT'], true)) {
            return null;
        }

        // Speedy's pair: x = longitude, y = latitude.
        $lat = $address['y'] ?? null;
        $lng = $address['x'] ?? null;
        $coordinates = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && ! ((float) $lat === 0.0 && (float) $lng === 0.0)
            ? [round((float) $lat, 6), round((float) $lng, 6)]
            : [null, null];

        try {
            return new PickupPoint(
                carrierCode: SpeedyDescriptor::CODE,
                reference: (string) $id,
                name: $name,
                countryCode: $countryCode,
                settlement: $settlement,
                addressLine: $addressLine,
                kind: $type === 'APT' ? PickupPointKind::LOCKER : PickupPointKind::OFFICE,
                postcode: $postcode !== null && strlen($postcode) <= 12 ? $postcode : null,
                latitude: $coordinates[0],
                longitude: $coordinates[1],
                courierType: $type,
                courierSettlementRef: $siteId,
                features: $this->features($item),
            );
        } catch (InvalidCarrierDataException|Throwable) {
            return null;
        }
    }

    /**
     * The office flags kept for COUR-2a (no filtering here): booleans as given, `validTo` as the string Speedy sent.
     *
     * @param  array<mixed>  $item
     * @return array<string, bool|string>
     */
    private function features(array $item): array
    {
        $features = [];

        foreach (['pickUpAllowed', 'cardPaymentAllowed', 'cashPaymentAllowed'] as $flag) {
            if (is_bool($item[$flag] ?? null)) {
                $features[$flag] = $item[$flag];
            }
        }

        $validTo = $this->text($item['validTo'] ?? null);

        if ($validTo !== null) {
            $features['validTo'] = $validTo;
        }

        return $features;
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
