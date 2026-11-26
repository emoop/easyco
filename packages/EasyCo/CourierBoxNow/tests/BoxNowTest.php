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

    public function test_the_real_shaped_sample_maps_to_lockers(): void
    {
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, $this->fixture()));

        $batch = (new BoxNowCatalogue($http))->allPoints($this->credentials(), 'BG');

        $this->assertCount(3, $batch->points);
        $this->assertSame(0, $batch->malformed);

        [$sofia, $plovdiv, $varna] = $batch->points;

        $this->assertSame('2', $sofia->reference);
        $this->assertSame(PickupPointKind::LOCKER, $sofia->kind);
        $this->assertSame('BOX NOW Sofia Lozenets', $sofia->name, '`name` comes before `title`');
        $this->assertSame('bul. Cherni vrah 47', $sofia->addressLine);
        $this->assertSame('София', $sofia->settlement, 'addressLine2 is the city name');
        $this->assertSame('1421', $sofia->postcode);
        $this->assertSame(42.6741, $sofia->latitude, 'lat / lng are strings in the list');
        $this->assertSame(23.3195, $sofia->longitude);
        $this->assertSame('boxnow', $sofia->carrierCode);
        $this->assertSame('BG', $sofia->countryCode);
        $this->assertSame(['state' => 'boxnow-ready'], $sofia->features);

        $this->assertSame('only-delivery', $plovdiv->features['state'], 'an only-delivery locker is kept: it can receive a delivery');
        $this->assertSame('Пловдив', $plovdiv->settlement);

        $this->assertSame('BOX NOW Varna Mall', $varna->name, '`title` is the fallback when there is no `name`');
        $this->assertSame('Варна', $varna->settlement);
    }

    public function test_malformed_items_are_skipped_and_counted_and_an_old_style_line2_still_gives_the_city(): void
    {
        $items = [
            ['type' => 'apm', 'name' => 'No id', 'addressLine1' => 'Street 1', 'addressLine2' => 'София'],
            ['id' => '5', 'type' => 'any-apm', 'name' => 'Pseudo', 'addressLine1' => '-', 'addressLine2' => 'София'],
            ['id' => '6', 'type' => 'apm', 'name' => 'No address', 'addressLine2' => 'София'],
            ['id' => 'bad id!', 'type' => 'apm', 'name' => 'Unsafe', 'addressLine1' => 'Street 1', 'addressLine2' => 'София'],
            ['id' => '7', 'type' => 'apm', 'name' => 'No city', 'addressLine1' => 'Street 1'],
            ['id' => '8', 'type' => 'apm', 'name' => 'Old style', 'addressLine1' => 'Street 8', 'addressLine2' => '1000, Sofia', 'lat' => '1', 'lng' => 'x'],
        ];

        $batch = (new BoxNowCatalogue((new FakeCourierHttp)->answer(new CourierResponse(200, (string) json_encode(['data' => $items])))))->allPoints($this->credentials(), 'BG');

        $this->assertSame(5, $batch->malformed);
        $this->assertCount(1, $batch->points);
        $this->assertSame('Sofia', $batch->points[0]->settlement);
        $this->assertSame('1000', $batch->points[0]->postcode, 'a postcode inside line 2 is the fallback');
        $this->assertNull($batch->points[0]->latitude, 'half a pair is dropped');
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
