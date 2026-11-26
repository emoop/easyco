<?php

namespace EasyCo\CourierSpeedy\Tests;

use EasyCo\CourierSpeedy\SpeedyCatalogue;
use EasyCo\CourierSpeedy\SpeedyDescriptor;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierRedactor;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use PHPUnit\Framework\TestCase;

/** The Speedy adapter against fixtures built from the documented shape (not live recordings) and a fake CourierHttp: no network, fake credentials. */
final class SpeedyTest extends TestCase
{
    private const USER = 'fake-user-for-tests';

    private const PASS = 'fake-password-for-tests';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    private function credentials(bool $filled = true): CourierCredentials
    {
        return new CourierCredentials('speedy', CourierEnvironment::TEST, $filled ? ['username' => self::USER, 'password' => self::PASS] : []);
    }

    public function test_offices_and_automats_map_with_their_site_id(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture('offices.sample.json')));

        $batch = (new SpeedyCatalogue($http))->allPoints($this->credentials(), 'BG');

        $this->assertCount(2, $batch->points);
        $this->assertSame(1, $batch->malformed, 'an office with no type is skipped and counted');

        [$office, $apt] = $batch->points;

        $this->assertSame('100', $office->reference, 'the office id is the reference');
        $this->assertSame(PickupPointKind::OFFICE, $office->kind);
        $this->assertSame('OFFICE', $office->courierType);
        $this->assertSame('ОФИС ЛОЗЕНЕЦ', $office->name);
        $this->assertSame('СОФИЯ', $office->settlement);
        $this->assertSame('68134', $office->courierSettlementRef);
        $this->assertSame('1000', $office->postcode);
        $this->assertSame(42.6726, $office->latitude, 'y is the latitude');
        $this->assertSame(23.3197, $office->longitude, 'x is the longitude');

        $this->assertSame(PickupPointKind::LOCKER, $apt->kind);
        $this->assertSame('APT', $apt->courierType);
        $this->assertSame('56784', $apt->courierSettlementRef);
        $this->assertSame(42.1354, $apt->latitude, 'string coordinates are read');
    }

    public function test_the_request_carries_the_credentials_in_the_body_and_country_100_on_the_one_host(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"offices": []}'));
        (new SpeedyCatalogue($http))->allPoints($this->credentials(), 'BG');

        $call = $http->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('api.speedy.bg', $call['host']);
        $this->assertSame('/v1/location/office', $call['path']);
        $this->assertSame(100, $call['body']['countryId']);
        $this->assertSame(self::USER, $call['body']['userName']);
        $this->assertSame(self::PASS, $call['body']['password']);
        $this->assertTrue($call['call']->retryable);

        $this->assertSame('BG', $call['body']['language'], 'names come in Cyrillic, like the backbone');
    }

    public function test_the_settlements_come_from_the_csv_export_read_by_header_names(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture('sites.sample.csv')));
        $sites = (new SpeedyCatalogue($http))->settlements($this->credentials(), 'BG');

        $call = $http->calls[0];
        $this->assertSame('/v1/location/site/csv/100', $call['path'], 'the FULL list, not the location/site search');
        $this->assertSame('api.speedy.bg', $call['host']);
        $this->assertSame([self::USER, self::PASS, 'BG'], [$call['body']['userName'], $call['body']['password'], $call['body']['language']]);
        $this->assertArrayNotHasKey('countryId', $call['body'], 'the country is in the path');
        $this->assertTrue($call['call']->retryable);

        $this->assertSame(['68134', '56784', '10001'], array_map(static fn ($s) => $s->reference, $sites), 'the BOM on the first header is dropped, the broken id is left out');
        $this->assertSame('СОФИЯ', $sites[0]->name);
        $this->assertSame('СОФИЯ (СТОЛИЦА)', $sites[0]->regionName);
        $this->assertSame('1000', $sites[0]->postcode);
        $this->assertSame('БОВ', $sites[2]->name, 'a quoted field');
        $this->assertSame('СОФИЯ', $sites[2]->regionName);
    }

    public function test_the_csv_columns_are_found_by_name_whatever_their_order_and_delimiter(): void
    {
        $csv = "region;postCode;name;id;extra\n\"ПЛОВДИВ\";4000;\"ПЛОВДИВ\";56784;x\n";
        $sites = (new SpeedyCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, $csv))))->settlements($this->credentials(), 'BG');

        $this->assertCount(1, $sites);
        $this->assertSame(['56784', 'ПЛОВДИВ', '4000', 'ПЛОВДИВ'], [$sites[0]->reference, $sites[0]->name, $sites[0]->postcode, $sites[0]->regionName]);
    }

    public function test_a_csv_without_an_id_or_name_column_or_a_json_error_is_a_closed_code(): void
    {
        foreach ([["id,region\n1,x\n", 'invalid_response'], ["name,region\nA,x\n", 'invalid_response'], ['', 'invalid_response'], ['{"error":{"message":"bad user '.self::USER.'"}}', 'auth_failed'], ['{"sites": []}', 'invalid_response']] as [$body, $code]) {
            try {
                (new SpeedyCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, $body))))->settlements($this->credentials(), 'BG');
                $this->fail('must fail');
            } catch (CourierHttpException $e) {
                $this->assertSame($code, $e->closedCode, $body);
                $this->assertStringNotContainsString(self::USER, $e->getMessage());
            }
        }

        $http = new FakeCourierHttp;
        $this->expectException(CourierHttpException::class);
        (new SpeedyCatalogue($http))->settlements($this->credentials(false), 'BG');
    }

    public function test_the_office_flags_are_kept_in_the_features_and_nothing_is_filtered_on_them(): void
    {
        $batch = (new SpeedyCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture('offices.sample.json')))))->allPoints($this->credentials(), 'BG');

        $this->assertCount(2, $batch->points, 'an office that is not pickUpAllowed is still stored');
        $this->assertSame(['pickUpAllowed' => true, 'cardPaymentAllowed' => true, 'cashPaymentAllowed' => false, 'validTo' => '2030-12-31'], $batch->points[0]->features);
        $this->assertSame(['pickUpAllowed' => false, 'cardPaymentAllowed' => true, 'cashPaymentAllowed' => true], $batch->points[1]->features);
    }

    public function test_a_site_id_that_is_not_digits_keeps_the_office_without_a_settlement_ref(): void
    {
        $address = ['siteName' => 'СОФИЯ', 'fullAddressString' => 'ул. Тест 1'];
        $items = [
            ['id' => 1, 'name' => 'A', 'type' => 'OFFICE', 'siteId' => 'x1', 'address' => $address],
            ['id' => 2, 'name' => 'B', 'type' => 'APT', 'siteId' => str_repeat('9', 19), 'address' => $address],
        ];

        $batch = (new SpeedyCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, (string) json_encode(['offices' => $items])))))->allPoints($this->credentials(), 'BG');

        $this->assertSame([0, 2], [$batch->malformed, count($batch->points)]);
        $this->assertNull($batch->points[0]->courierSettlementRef);
        $this->assertNull($batch->points[1]->courierSettlementRef);
    }

    public function test_without_credentials_nothing_is_sent_and_a_refusal_is_a_closed_code_that_never_echoes(): void
    {
        $http = new FakeCourierHttp;

        try {
            (new SpeedyCatalogue($http))->allPoints($this->credentials(false), 'BG');
            $this->fail('Speedy needs credentials even for its lists');
        } catch (CourierHttpException $e) {
            $this->assertSame('not_configured', $e->closedCode);
        }

        $this->assertSame([], $http->calls);

        foreach ([[401, 'x', 'auth_failed'], [200, '{"error":{"message":"bad user '.self::USER.'"}}', 'auth_failed'], [503, 'x', 'courier_unavailable'], [200, '{"offices":"x"}', 'invalid_response']] as [$status, $body, $code]) {
            try {
                (new SpeedyCatalogue((new FakeCourierHttp)->answer(new CourierResponse($status, $body))))->allPoints($this->credentials(), 'BG');
                $this->fail('must fail');
            } catch (CourierHttpException $e) {
                $this->assertSame($code, $e->closedCode);
                $this->assertStringNotContainsString(self::USER, $e->getMessage().json_encode($e->closedCode));
            }
        }
    }

    public function test_the_connection_check_and_the_redactor_keep_the_credentials_out(): void
    {
        $d = new SpeedyDescriptor;

        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"clients": [{"clientId": 1}]}'));
        $this->assertTrue($d->checkConnection($http, $this->credentials())->ok);
        $this->assertTrue($d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(200, '{"clients": []}')), $this->credentials())->ok, 'an empty list of clients is still a list');
        $this->assertSame('invalid_response', $d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(200, '{"nothing": 1}')), $this->credentials())->code);
        $this->assertSame('invalid_response', $d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(200, '{"clients": "x"}')), $this->credentials())->code);
        $this->assertSame('/v1/client/contract', $http->calls[0]['path']);

        $this->assertSame('not_configured', $d->checkConnection(new FakeCourierHttp, $this->credentials(false))->code);
        $this->assertSame('auth_failed', $d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(200, '{"error":{"message":"x"}}')), $this->credentials())->code);
        $this->assertSame('auth_failed', $d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(403, 'LEAK')), $this->credentials())->code);
        $this->assertSame(['api.speedy.bg'], $d->hosts(CourierEnvironment::PRODUCTION, []));

        // What a snapshot of a Speedy request would keep once redacted.
        $snapshot = CourierRedactor::redact(['userName' => self::USER, 'password' => self::PASS, 'language' => 'BG', 'nested' => ['Authorization' => 'Bearer x', 'countryId' => 100]]);
        $this->assertStringNotContainsString(self::USER, (string) json_encode($snapshot));
        $this->assertStringNotContainsString(self::PASS, (string) json_encode($snapshot));
        $this->assertSame(100, $snapshot['nested']['countryId']);
    }
}
