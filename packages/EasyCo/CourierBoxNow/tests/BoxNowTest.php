<?php

namespace EasyCo\CourierBoxNow\Tests;

use EasyCo\CourierBoxNow\BoxNowCatalogue;
use EasyCo\CourierBoxNow\BoxNowDescriptor;
use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use PHPUnit\Framework\TestCase;

/**
 * The BOX NOW adapter against a FIXTURE (a constructed sample of the public locker list's documented shape, not a live recording)
 * and a fake CourierHttp: no network. See BoxNowCatalogue for the field-name assumptions.
 */
final class BoxNowTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/apms_bg-BG.sample.json');
    }

    private function credentials(CourierEnvironment $environment = CourierEnvironment::TEST, array $values = []): CourierCredentials
    {
        return new CourierCredentials('boxnow', $environment, $values);
    }

    public function test_the_fixture_maps_to_lockers_and_the_malformed_items_are_counted(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture()));

        $batch = (new BoxNowCatalogue($http))->allPoints($this->credentials(), 'BG');

        $this->assertCount(3, $batch->points);
        $this->assertSame(4, $batch->malformed, 'no id, a non-apm type, no address, an unsafe id');

        [$sofia, $plovdiv, $varna] = $batch->points;

        $this->assertSame('2001', $sofia->reference);
        $this->assertSame(PickupPointKind::LOCKER, $sofia->kind);
        $this->assertSame('BOX NOW Test Mall Sofia', $sofia->name);
        $this->assertSame('Test Blvd 1', $sofia->addressLine);
        $this->assertSame('Sofia', $sofia->settlement);
        $this->assertSame('1000', $sofia->postcode);
        $this->assertSame(42.6977, $sofia->latitude);
        $this->assertSame(23.3219, $sofia->longitude);
        $this->assertSame('boxnow', $sofia->carrierCode);
        $this->assertSame('BG', $sofia->countryCode);

        $this->assertSame('2002', $plovdiv->reference, 'an integer id is the same locker id as a string');
        $this->assertSame('BOX NOW Plovdiv Test', $plovdiv->name, 'name is the fallback for title');
        $this->assertSame('4000', $plovdiv->postcode, 'the postcode is read from addressLine2 when postalCode is absent');
        $this->assertSame('Plovdiv', $plovdiv->settlement);

        $this->assertSame('Varna', $varna->settlement, 'an explicit city wins');
        $this->assertNull($varna->latitude, 'half a coordinate pair is dropped, the locker is kept');
        $this->assertNull($varna->longitude);
    }

    public function test_a_bare_list_root_is_accepted_and_a_non_list_is_refused(): void
    {
        $bare = json_encode([json_decode($this->fixture(), true)['data'][0]]);
        $batch = (new BoxNowCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, (string) $bare))))->allPoints($this->credentials(), 'BG');
        $this->assertCount(1, $batch->points);

        foreach (['not json', '{"data": "x"}', '{"a": 1}', '"text"'] as $body) {
            try {
                (new BoxNowCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, $body))))->allPoints($this->credentials(), 'BG');
                $this->fail("a non-list body must be refused: {$body}");
            } catch (CourierHttpException $e) {
                $this->assertSame(CourierHttpException::INVALID_RESPONSE, $e->closedCode);
            }
        }
    }

    public function test_a_non_success_answer_is_a_closed_code_and_another_country_is_refused(): void
    {
        try {
            (new BoxNowCatalogue((new FakeCourierHttp)->answer(new CourierResponse(503, 'secret upstream text'))))->allPoints($this->credentials(), 'BG');
            $this->fail('503 must fail');
        } catch (CourierHttpException $e) {
            $this->assertSame(CourierHttpException::UNAVAILABLE, $e->closedCode);
            $this->assertStringNotContainsString('secret upstream text', $e->getMessage());
        }

        $this->expectException(CourierHttpException::class);
        (new BoxNowCatalogue(new FakeCourierHttp))->allPoints($this->credentials(), 'RO');
    }

    public function test_the_list_comes_from_the_environments_public_host_as_a_sync_read_without_credentials(): void
    {
        foreach ([[CourierEnvironment::PRODUCTION, 'locationapi-production.boxnow.bg'], [CourierEnvironment::TEST, 'locationapi-stage.boxnow.bg']] as [$environment, $host]) {
            $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '[]'));
            (new BoxNowCatalogue($http))->allPoints($this->credentials($environment, ['client_secret' => 'must-not-be-sent']), 'BG');

            $call = $http->calls[0];
            $this->assertSame($host, $call['host']);
            $this->assertSame('/v1/apms_bg-BG.json', $call['path']);
            $this->assertSame([], $call['query']);
            $this->assertSame([], $call['body']);
            $this->assertTrue($call['call']->retryable, 'a list download is a read');
            $this->assertSame(CallKind::SYNC, $call['call']->kind);
        }
    }

    public function test_the_hosts_are_the_list_host_plus_a_valid_api_host_only(): void
    {
        $d = new BoxNowDescriptor;

        $this->assertSame(['locationapi-stage.boxnow.bg'], $d->hosts(CourierEnvironment::TEST, []));
        $this->assertSame(['locationapi-production.boxnow.bg', 'api-production.boxnow.bg'], $d->hosts(CourierEnvironment::PRODUCTION, ['api_host' => 'API-production.boxnow.bg']));

        foreach (['evil.example.com', 'boxnow.bg.evil.com', 'https://api.boxnow.bg', 'api.boxnow.bg:8443', 'api.boxnow.bg/x', 'xboxnow.bg', '127.0.0.1', ''] as $bad) {
            $this->assertSame(['locationapi-stage.boxnow.bg'], $d->hosts(CourierEnvironment::TEST, ['api_host' => $bad]), "refused: {$bad}");
        }
    }

    public function test_the_connection_check_reports_ok_or_a_closed_code_and_never_a_body(): void
    {
        $d = new BoxNowDescriptor;
        $full = ['api_host' => 'api-stage.boxnow.bg', 'client_id' => 'cid', 'client_secret' => 'csecret'];

        // OK: only the shape of the token answer is checked.
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"access_token":"tok","expires_in":3600}'));
        $this->assertTrue($d->checkConnection($http, $this->credentials(CourierEnvironment::TEST, $full))->ok);
        $this->assertSame('api-stage.boxnow.bg', $http->calls[0]['host']);
        $this->assertSame('/api/v1/auth-sessions', $http->calls[0]['path']);
        $this->assertSame('client_credentials', $http->calls[0]['body']['grant_type']);

        $cases = [
            [new CourierResponse(401, 'sensitive body'), 'auth_failed'],
            [new CourierResponse(403, 'sensitive body'), 'account_disabled'],
            [new CourierResponse(502, 'sensitive body'), 'courier_unavailable'],
            [new CourierResponse(200, '{"nothing":1}'), 'invalid_response'],
            [new CourierHttpException(CourierHttpException::TIMEOUT), 'timeout'],
            [new CourierHttpException(CourierHttpException::HOST_NOT_ALLOWED), 'host_not_allowed'],
        ];

        foreach ($cases as [$answer, $code]) {
            $result = $d->checkConnection((new FakeCourierHttp)->answer($answer), $this->credentials(CourierEnvironment::TEST, $full));
            $this->assertFalse($result->ok);
            $this->assertSame($code, $result->code);
        }

        // Missing pieces: no request is made at all.
        $http = new FakeCourierHttp;
        $this->assertSame('api_host_missing', $d->checkConnection($http, $this->credentials(CourierEnvironment::TEST, ['client_id' => 'a', 'client_secret' => 'b']))->code);
        $this->assertSame('not_configured', $d->checkConnection($http, $this->credentials(CourierEnvironment::TEST, ['api_host' => 'api-stage.boxnow.bg']))->code);
        $this->assertSame('api_host_missing', $d->checkConnection($http, $this->credentials(CourierEnvironment::TEST, ['api_host' => 'evil.example.com', 'client_id' => 'a', 'client_secret' => 'b']))->code);
        $this->assertSame([], $http->calls);
    }

    public function test_credentials_never_print_a_value(): void
    {
        $credentials = $this->credentials(CourierEnvironment::TEST, ['client_secret' => 'TOP-SECRET-VALUE', 'client_id' => 'CLIENT-ID-VALUE']);

        $dumps = [(string) $credentials, print_r($credentials, true), var_export($credentials, true), json_encode($credentials), sprintf('%s', $credentials)];
        ob_start();
        var_dump($credentials);
        $dumps[] = (string) ob_get_clean();

        foreach ($dumps as $dump) {
            $this->assertStringNotContainsString('TOP-SECRET-VALUE', (string) $dump);
            $this->assertStringNotContainsString('CLIENT-ID-VALUE', (string) $dump);
        }

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }
}
