<?php

namespace EasyCo\CourierEcont;

use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\ConnectionCheck;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierField;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Contracts\CourierDescriptor;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Exceptions\CourierHttpException;

/**
 * Econt's description of itself (courier-integration-design.md §5, §7.2, §10.2).
 *
 * HOSTS are fixed per environment, https only: production `ee.econt.com` (services under `/services`), test `demo.econt.com`
 * (services under `/ee/services`). Nothing the merchant types can change them.
 *
 * CREDENTIALS: a user name and a password (HTTP Basic), per environment, both optional for the office and city lists
 * (P: production answers `getOffices` without them). The test environment's public demo credentials are entered by the merchant in
 * the admin; they are not in this package, a fixture or a test.
 */
final class EcontDescriptor implements CourierDescriptor
{
    public const CODE = 'econt';

    public const HOSTS = [
        'production' => 'ee.econt.com',
        'test' => 'demo.econt.com',
    ];

    private const PATH_PREFIX = [
        'production' => '/services',
        'test' => '/ee/services',
    ];

    public function code(): string
    {
        return self::CODE;
    }

    public function fields(): array
    {
        return [
            new CourierField('username', 'econt.username', CourierField::TEXT, true, 120),
            new CourierField('password', 'econt.password', CourierField::SECRET, true, 200),
        ];
    }

    public function hosts(CourierEnvironment $environment, array $plainValues): array
    {
        return [self::HOSTS[$environment->value]];
    }

    /** The path of one Econt service call in an environment, e.g. `Nomenclatures/NomenclaturesService.getOffices.json`. */
    public static function path(CourierEnvironment $environment, string $service): string
    {
        return self::PATH_PREFIX[$environment->value].'/'.$service;
    }

    /** The Basic header of a credentials object, or none (the lists need none on production). @return array<string, string> */
    public static function authHeaders(CourierCredentials $credentials): array
    {
        $user = $credentials->value('username');
        $password = $credentials->value('password');

        return $user !== null && $password !== null ? ['Authorization' => 'Basic '.base64_encode($user.':'.$password)] : [];
    }

    public function checkConnection(CourierHttp $http, CourierCredentials $credentials): ConnectionCheck
    {
        $headers = self::authHeaders($credentials);

        if ($headers === []) {
            return ConnectionCheck::failed('not_configured');
        }

        try {
            // UNVERIFIED call: the client profiles of the account, a read that needs authentication and changes nothing.
            $response = $http->postJson(
                self::HOSTS[$credentials->environment()->value],
                self::path($credentials->environment(), 'Profile/ProfileService.getClientProfiles.json'),
                [],
                HttpCall::read(self::CODE, 'client_profiles', CallKind::ADMIN),
                $headers,
            );
        } catch (CourierHttpException $e) {
            return ConnectionCheck::failed($e->storedCode());
        }

        if ($response->successful()) {
            return is_array($response->json()) ? ConnectionCheck::ok() : ConnectionCheck::failed('invalid_response');
        }

        return ConnectionCheck::failed(match (true) {
            in_array($response->status, [400, 401], true) => 'auth_failed',
            $response->status === 403 => 'account_disabled',
            $response->status === 404 => 'not_found',
            $response->status === 429 => 'rate_limited',
            $response->status >= 500 => 'courier_unavailable',
            default => 'http_error',
        });
    }
}
