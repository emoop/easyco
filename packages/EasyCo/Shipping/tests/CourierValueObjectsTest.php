<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Carrier\AllowedHost;
use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\ConnectionCheck;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierField;
use EasyCo\Shipping\Carrier\CourierRedactor;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\CourierSettlement;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use PHPUnit\Framework\TestCase;

/** The COUR-1a value objects: validated at construction, and nothing that can print a secret. */
final class CourierValueObjectsTest extends TestCase
{
    public function test_credentials_hide_every_value_in_every_dump_and_refuse_serialisation(): void
    {
        $credentials = new CourierCredentials('boxnow', CourierEnvironment::PRODUCTION, ['client_secret' => 'S3CRET-VALUE', 'client_id' => 'ID-VALUE']);

        $this->assertSame('S3CRET-VALUE', $credentials->value('client_secret'));
        $this->assertNull($credentials->value('missing'));
        $this->assertSame(CourierEnvironment::PRODUCTION, $credentials->environment());

        ob_start();
        var_dump($credentials);
        $dumps = [(string) $credentials, print_r($credentials, true), var_export($credentials, true), (string) json_encode($credentials), (string) ob_get_clean(), print_r((array) $credentials, true)];

        foreach ($dumps as $dump) {
            $this->assertStringNotContainsString('S3CRET-VALUE', $dump);
            $this->assertStringNotContainsString('ID-VALUE', $dump);
        }

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    public function test_an_unknown_stored_environment_is_test(): void
    {
        $this->assertSame(CourierEnvironment::TEST, CourierEnvironment::fromStored(null));
        $this->assertSame(CourierEnvironment::TEST, CourierEnvironment::fromStored('live'));
        $this->assertSame(CourierEnvironment::PRODUCTION, CourierEnvironment::fromStored('production'));
    }

    public function test_an_allowed_host_is_a_bare_name_on_or_under_a_listed_domain(): void
    {
        $suffixes = ['boxnow.bg', 'boxnow.gr'];

        $this->assertSame('api.boxnow.bg', AllowedHost::clean(' API.boxnow.BG ', $suffixes));
        $this->assertSame('boxnow.gr', AllowedHost::clean('boxnow.gr', $suffixes));

        foreach (['', 'localhost', 'evil.com', 'boxnow.bg.evil.com', 'xboxnow.bg', 'https://api.boxnow.bg', 'api.boxnow.bg:443', 'api.boxnow.bg/', 'a@api.boxnow.bg', 'api.boxnow.bg.', '192.168.0.1', 'api .boxnow.bg', str_repeat('a', 64).'.boxnow.bg'] as $bad) {
            $this->assertNull(AllowedHost::clean($bad, $suffixes), "refused: {$bad}");
        }
    }

    public function test_a_host_field_needs_an_allowlist_and_a_valid_name(): void
    {
        $field = new CourierField('api_host', 'x.api_host', CourierField::HOST, true, 100, ['boxnow.bg']);
        $this->assertSame('a.boxnow.bg', $field->cleanHost('a.boxnow.bg'));
        $this->assertNull($field->cleanHost('a.example.com'));
        $this->assertTrue((new CourierField('s', 'x.s', CourierField::SECRET))->isSecret());

        foreach ([['Bad Name', CourierField::TEXT, []], ['ok', 'weird', []], ['ok', CourierField::HOST, []]] as [$name, $kind, $suffixes]) {
            try {
                new CourierField($name, 'x', $kind, true, 10, $suffixes);
                $this->fail('must be refused');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_http_call_has_a_fixed_operation_name_and_knows_if_it_is_a_read(): void
    {
        $read = HttpCall::read('boxnow', 'locker_list', CallKind::SYNC, 'c1');
        $write = HttpCall::write('boxnow', 'create', CallKind::ADMIN);

        $this->assertTrue($read->retryable);
        $this->assertFalse($write->retryable);
        $this->assertSame('c1', $read->correlationId);
        $this->assertNotSame('', $write->correlationId);
        $this->assertSame([60, 15, 20], [CallKind::SYNC->timeoutSeconds(), CallKind::ADMIN->timeoutSeconds(), CallKind::BACKGROUND->timeoutSeconds()]);
        $this->assertSame([20 * 1048576, 5 * 1048576, 5 * 1048576], [CallKind::SYNC->maxResponseBytes(), CallKind::ADMIN->maxResponseBytes(), CallKind::BACKGROUND->maxResponseBytes()]);

        foreach (['', 'has space', 'https://x', str_repeat('a', 65)] as $bad) {
            try {
                HttpCall::read('boxnow', $bad, CallKind::ADMIN);
                $this->fail("an operation name of \"{$bad}\" must be refused");
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_response_never_prints_its_body_and_decodes_only_json_containers(): void
    {
        $response = new CourierResponse(200, '{"a":1}');

        $this->assertStringNotContainsString('"a"', print_r($response, true));
        $this->assertSame(['a' => 1], $response->json());
        $this->assertNull((new CourierResponse(200, '"text"'))->json());
        $this->assertNull((new CourierResponse(200, 'nope'))->json());
        $this->assertTrue($response->successful());
        $this->assertFalse((new CourierResponse(302, ''))->successful());
    }

    public function test_a_connection_check_only_carries_a_closed_code(): void
    {
        $this->assertTrue(ConnectionCheck::ok()->ok);
        $this->assertSame('auth_failed', ConnectionCheck::failed('auth_failed')->code);
        $this->assertSame('unknown_error', ConnectionCheck::failed('<script>alert(1)</script> the body')->code);
    }

    public function test_a_pickup_point_validates_its_coordinates_and_carries_the_new_fields(): void
    {
        $point = new PickupPoint('boxnow', 'L1', 'Locker', 'BG', 'Sofia', 'Street 1', PickupPointKind::LOCKER, '1000', 42.6977, 23.3219, 'apm');
        $this->assertSame(PickupPointKind::LOCKER, $point->kind);
        $this->assertSame('1000', $point->postcode);

        foreach ([[91.0, 10.0], [10.0, 181.0], [10.0, null], [null, 10.0]] as [$lat, $lng]) {
            try {
                new PickupPoint('boxnow', 'L1', 'Locker', 'BG', 'Sofia', 'Street 1', PickupPointKind::LOCKER, null, $lat, $lng);
                $this->fail('bad coordinates must be refused');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_redactor_removes_credentials_at_any_depth_and_keeps_the_rest(): void
    {
        $out = CourierRedactor::redact(['userName' => 'u', 'PASSWORD' => 'p', 'a' => ['client_secret' => 's', 'x' => ['Authorization' => 'Bearer t', 'access_token' => 't', 'keep' => 1]], 'list' => [['password' => 'p', 'id' => 5]]]);

        $this->assertSame(CourierRedactor::MASK, $out['userName']);
        $this->assertSame(CourierRedactor::MASK, $out['PASSWORD']);
        $this->assertSame(CourierRedactor::MASK, $out['a']['client_secret']);
        $this->assertSame(CourierRedactor::MASK, $out['a']['x']['Authorization']);
        $this->assertSame(CourierRedactor::MASK, $out['a']['x']['access_token']);
        $this->assertSame(1, $out['a']['x']['keep']);
        $this->assertSame(CourierRedactor::MASK, $out['list'][0]['password']);
        $this->assertSame(5, $out['list'][0]['id']);
    }

    public function test_a_courier_settlement_and_the_settlement_ref_of_a_point_are_validated(): void
    {
        $s = new CourierSettlement('speedy', '68134', 'СОФИЯ', '1000', 'СОФИЯ (СТОЛИЦА)');
        $this->assertSame('68134', $s->reference);

        foreach ([['Speedy', '1', 'x'], ['speedy', '', 'x'], ['speedy', '1', ' ']] as [$carrier, $ref, $name]) {
            try {
                new CourierSettlement($carrier, $ref, $name);
                $this->fail('must be refused');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }

        $point = new PickupPoint('speedy', '100', 'Office', 'BG', 'Sofia', 'Street 1', PickupPointKind::OFFICE, courierSettlementRef: '68134');
        $this->assertSame('68134', $point->courierSettlementRef);

        $this->assertSame(str_repeat('9', 18), (new PickupPoint('speedy', '100', 'Office', 'BG', 'Sofia', 'Street 1', PickupPointKind::OFFICE, courierSettlementRef: str_repeat('9', 18)))->courierSettlementRef);

        foreach ([str_repeat('9', 19), '12a', '-5', '1.5', ' 7'] as $bad) {
            try {
                new PickupPoint('speedy', '100', 'Office', 'BG', 'Sofia', 'Street 1', PickupPointKind::OFFICE, courierSettlementRef: $bad);
                $this->fail("a settlement ref of \"{$bad}\" must be refused");
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_descriptor_container_key_is_one_convention(): void
    {
        $this->assertSame('shipping.carrier.boxnow.descriptor', CarrierRegistration::descriptorKey('boxnow'));
    }
}
