<?php

namespace EasyCo\CourierEcont\Tests;

use EasyCo\CourierEcont\EcontCatalogue;
use EasyCo\CourierEcont\EcontDescriptor;
use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use PHPUnit\Framework\TestCase;

/** The Econt adapter against fixtures built from the documented shape (not live recordings) and a fake CourierHttp: no network. */
final class EcontTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    private function credentials(CourierEnvironment $environment = CourierEnvironment::PRODUCTION, array $values = []): CourierCredentials
    {
        return new CourierCredentials('econt', $environment, $values);
    }

    public function test_offices_map_to_offices_and_automatic_stations(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture('offices.sample.json')));

        $batch = (new EcontCatalogue($http))->allPoints($this->credentials(), 'BG');

        $this->assertCount(3, $batch->points);
        $this->assertSame(0, $batch->malformed);

        [$office, $aps, $mps] = $batch->points;

        $this->assertSame('1101', $office->reference, 'the office CODE is the reference');
        $this->assertSame('1101', $office->externalId);
        $this->assertSame(PickupPointKind::OFFICE, $office->kind);
        $this->assertSame('office', $office->courierType);
        $this->assertSame('София Младост 1', $office->name);
        $this->assertSame('София', $office->settlement);
        $this->assertSame('1000', $office->postcode);
        $this->assertSame('41', $office->courierSettlementRef, 'the city id joins the point to the settlement');
        $this->assertSame('ж.к. Младост 1, бл. 7', $office->addressLine);
        $this->assertSame(42.6501, $office->latitude);
        $this->assertSame(23.3792, $office->longitude);

        $this->assertSame(PickupPointKind::LOCKER, $aps->kind);
        $this->assertSame('aps', $aps->courierType);
        $this->assertSame('ул. Граф Игнатиев 12', $aps->addressLine, 'street and number when there is no full address');
        $this->assertSame(42.6903, $aps->latitude, 'string coordinates are read');

        $this->assertSame(PickupPointKind::OFFICE, $mps->kind, 'only the automatic station is a locker');
        $this->assertSame('mps', $mps->courierType);
        $this->assertNull($mps->latitude, '0 / 0 means unknown');
    }

    public function test_the_request_is_the_nomenclature_call_for_bulgaria_on_the_environments_host(): void
    {
        foreach ([
            [CourierEnvironment::PRODUCTION, 'ee.econt.com', '/services/Nomenclatures/NomenclaturesService.getOffices.json'],
            [CourierEnvironment::TEST, 'demo.econt.com', '/ee/services/Nomenclatures/NomenclaturesService.getOffices.json'],
        ] as [$environment, $host, $path]) {
            $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"offices": []}'));
            (new EcontCatalogue($http))->allPoints($this->credentials($environment), 'BG');

            $call = $http->calls[0];
            $this->assertSame('POST', $call['method']);
            $this->assertSame($host, $call['host']);
            $this->assertSame($path, $call['path']);
            $this->assertSame(['countryCode' => 'BGR'], $call['body']);
            $this->assertTrue($call['call']->retryable);
            $this->assertSame(CallKind::SYNC, $call['call']->kind);
        }
    }

    public function test_cities_map_to_courier_settlements_and_broken_ones_are_left_out(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture('cities.sample.json')));

        $cities = (new EcontCatalogue($http))->settlements($this->credentials(), 'BG');

        $this->assertSame(['41', '25', '700'], array_map(static fn ($c) => $c->reference, $cities));
        $this->assertSame('Костинброд', $cities[2]->name);
        $this->assertSame('2230', $cities[2]->postcode);
        $this->assertSame('София', $cities[2]->regionName);
        $this->assertSame('/services/Nomenclatures/NomenclaturesService.getCities.json', $http->calls[0]['path']);
    }

    public function test_no_credentials_means_no_authorization_header_and_credentials_mean_basic(): void
    {
        $this->assertSame([], EcontDescriptor::authHeaders($this->credentials()));
        $this->assertSame([], EcontDescriptor::authHeaders($this->credentials(values: ['username' => 'u'])));
        $this->assertSame(['Authorization' => 'Basic '.base64_encode('u:p')], EcontDescriptor::authHeaders($this->credentials(values: ['username' => 'u', 'password' => 'p'])));
    }

    public function test_malformed_offices_are_skipped_and_counted_and_a_bad_answer_is_a_closed_code(): void
    {
        $items = [
            ['id' => 1, 'name' => 'No code', 'address' => ['city' => ['id' => 1, 'name' => 'София'], 'fullAddress' => 'x']],
            ['id' => 2, 'code' => 'bad code!', 'name' => 'Unsafe', 'address' => ['city' => ['id' => 1, 'name' => 'София'], 'fullAddress' => 'x']],
            ['id' => 3, 'code' => '3', 'name' => 'No city', 'address' => ['fullAddress' => 'x']],
            ['id' => 4, 'code' => '4', 'name' => 'No address', 'address' => ['city' => ['id' => 1, 'name' => 'София']]],
            ['id' => 5, 'code' => '5', 'address' => ['city' => ['id' => 1, 'name' => 'София'], 'fullAddress' => 'x']],
            'not an object',
        ];

        $batch = (new EcontCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, (string) json_encode(['offices' => $items])))))->allPoints($this->credentials(), 'BG');
        $this->assertSame([6, 0], [$batch->malformed, count($batch->points)]);

        foreach ([[200, 'nope', 'invalid_response'], [200, '{"offices":"x"}', 'invalid_response'], [503, 'secret text', 'courier_unavailable'], [401, 'secret text', 'auth_failed']] as [$status, $body, $code]) {
            try {
                (new EcontCatalogue((new FakeCourierHttp)->answer(new CourierResponse($status, $body))))->allPoints($this->credentials(), 'BG');
                $this->fail('must fail');
            } catch (CourierHttpException $e) {
                $this->assertSame($code, $e->closedCode);
                $this->assertStringNotContainsString('secret text', $e->getMessage());
            }
        }
    }

    public function test_a_city_id_that_is_not_digits_keeps_the_office_without_a_settlement_ref(): void
    {
        $item = ['id' => 5, 'code' => '5', 'name' => 'Office', 'address' => ['city' => ['id' => '12ab', 'name' => 'София'], 'fullAddress' => 'x']];
        $long = ['id' => 6, 'code' => '6', 'name' => 'Office 2', 'address' => ['city' => ['id' => str_repeat('9', 19), 'name' => 'София'], 'fullAddress' => 'x']];

        $batch = (new EcontCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, (string) json_encode(['offices' => [$item, $long]])))))->allPoints($this->credentials(), 'BG');

        $this->assertSame([0, 2], [$batch->malformed, count($batch->points)]);
        $this->assertNull($batch->points[0]->courierSettlementRef);
        $this->assertNull($batch->points[1]->courierSettlementRef);
    }

    public function test_the_hosts_are_fixed_per_environment_and_the_connection_check_needs_credentials(): void
    {
        $d = new EcontDescriptor;

        $this->assertSame(['ee.econt.com'], $d->hosts(CourierEnvironment::PRODUCTION, ['anything' => 'evil.example.com']));
        $this->assertSame(['demo.econt.com'], $d->hosts(CourierEnvironment::TEST, []));

        $http = new FakeCourierHttp;
        $this->assertSame('not_configured', $d->checkConnection($http, $this->credentials())->code);
        $this->assertSame([], $http->calls, 'no request without credentials');

        $creds = $this->credentials(CourierEnvironment::TEST, ['username' => 'demo-user', 'password' => 'demo-pass']);
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"profiles": []}'));
        $this->assertTrue($d->checkConnection($http, $creds)->ok);
        $this->assertSame('/ee/services/Profile/ProfileService.getClientProfiles.json', $http->calls[0]['path']);

        $this->assertSame('auth_failed', $d->checkConnection((new FakeCourierHttp)->answer(new CourierResponse(401, 'LEAK')), $creds)->code);
        $this->assertSame('timeout', $d->checkConnection((new FakeCourierHttp)->answer(new CourierHttpException(CourierHttpException::TIMEOUT)), $creds)->code);
    }
}
