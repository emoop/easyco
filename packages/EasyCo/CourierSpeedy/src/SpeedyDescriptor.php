<?php

namespace EasyCo\CourierSpeedy;

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
 * Speedy's description of itself (courier-integration-design.md §5, §7.3, §10.2). ONE host, `api.speedy.bg`, https only, for both
 * environments (Speedy's test world is a test ACCOUNT, not a test host). `userName` and `password` travel in the JSON BODY of every
 * call (D: the API's rule); they come from CourierCredentials only, and the body is never logged (the client logs no body) nor kept
 * (CourierRedactor drops both keys from any snapshot). SPEEDY NEEDS CREDENTIALS EVEN FOR ITS LISTS.
 */
final class SpeedyDescriptor implements CourierDescriptor
{
    public const CODE = 'speedy';

    public const HOST = 'api.speedy.bg';

    /** Speedy's id of Bulgaria (D: `countryId` 100, https://services.speedy.bg/api/api_examples.php). */
    public const COUNTRY_ID = 100;

    public function code(): string
    {
        return self::CODE;
    }

    public function fields(): array
    {
        return [
            new CourierField('username', 'speedy.username', CourierField::TEXT, true, 120),
            new CourierField('password', 'speedy.password', CourierField::SECRET, true, 200),
        ];
    }

    public function hosts(CourierEnvironment $environment, array $plainValues): array
    {
        return [self::HOST];
    }

    /**
     * The JSON body every Speedy call starts from.
     *
     * @return array<string, mixed>|null null when the user name or password is not filled in
     */
    public static function authBody(CourierCredentials $credentials): ?array
    {
        $user = $credentials->value('username');
        $password = $credentials->value('password');

        return $user === null || $password === null ? null : ['userName' => $user, 'password' => $password, 'language' => 'BG'];
    }

    public function checkConnection(CourierHttp $http, CourierCredentials $credentials): ConnectionCheck
    {
        $body = self::authBody($credentials);

        if ($body === null) {
            return ConnectionCheck::failed('not_configured');
        }

        try {
            // D (not yet live): the account's own contract clients, a read that needs authentication and changes nothing.
            $response = $http->postJson(self::HOST, '/v1/client/contract', $body, HttpCall::read(self::CODE, 'client_contract', CallKind::ADMIN));
        } catch (CourierHttpException $e) {
            return ConnectionCheck::failed($e->closedCode);
        }

        if ($response->successful()) {
            $json = $response->json();

            // D: GetContractClientsResponse, root `clients`, plus an optional `error` (which can come with HTTP 200).
            if (is_array($json) && isset($json['error'])) {
                return ConnectionCheck::failed('auth_failed');
            }

            return is_array($json) && is_array($json['clients'] ?? null) && array_is_list($json['clients']) ? ConnectionCheck::ok() : ConnectionCheck::failed('invalid_response');
        }

        return ConnectionCheck::failed(match (true) {
            in_array($response->status, [400, 401, 403], true) => 'auth_failed',
            $response->status === 404 => 'not_found',
            $response->status === 429 => 'rate_limited',
            $response->status >= 500 => 'courier_unavailable',
            default => 'http_error',
        });
    }
}
