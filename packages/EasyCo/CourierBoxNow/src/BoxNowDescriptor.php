<?php

namespace EasyCo\CourierBoxNow;

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
 * BOX NOW's description of itself (courier-integration-design.md §5, §7.1, §10.2).
 *
 * HOSTS. Two kinds:
 *  - the PUBLIC LOCKER LIST host, fixed per environment (design §7.1: `locationapi-production.boxnow.bg` / `locationapi-stage.boxnow.bg`);
 *  - the API HOST (`auth-sessions` and, from COUR-3a, vouchers): a per-environment value on the settings page, accepted ONLY if it
 *    is a bare hostname on or under `boxnow.bg` / `boxnow.gr` (a fixed allowlist, checked on save and again on every read). It is
 *    PRE-FILLED with `api-production.boxnow.bg` / `api-stage.boxnow.bg` (P: observed in a second independent implementation, not
 *    in BOX NOW's own public pages) and stays editable, so the connection test does not start with `api_host_missing`.
 *
 * No setting is read here and no facade is used: the application hands over the stored values.
 */
final class BoxNowDescriptor implements CourierDescriptor
{
    public const CODE = 'boxnow';

    /** The fixed list of BOX NOW domains an API host may be on. */
    public const API_HOST_DOMAINS = ['boxnow.bg', 'boxnow.gr'];

    /** P: the API hosts a second independent integration uses; pre-filled per environment, editable within the allowlist above. */
    public const API_HOST_DEFAULTS = [
        'production' => 'api-production.boxnow.bg',
        'test' => 'api-stage.boxnow.bg',
    ];

    public const LIST_HOSTS = [
        'production' => 'locationapi-production.boxnow.bg',
        'test' => 'locationapi-stage.boxnow.bg',
    ];

    public function code(): string
    {
        return self::CODE;
    }

    public function fields(): array
    {
        return [
            new CourierField('api_host', 'boxnow.api_host', CourierField::HOST, true, 100, self::API_HOST_DOMAINS, self::API_HOST_DEFAULTS),
            new CourierField('partner_id', 'boxnow.partner_id', CourierField::TEXT, true, 60),
            new CourierField('client_id', 'boxnow.client_id', CourierField::TEXT, true, 120),
            new CourierField('client_secret', 'boxnow.client_secret', CourierField::SECRET, true, 200),
        ];
    }

    public function hosts(CourierEnvironment $environment, array $plainValues): array
    {
        $hosts = [self::LIST_HOSTS[$environment->value]];

        $apiHost = isset($plainValues['api_host']) ? $this->apiHostField()->cleanHost($plainValues['api_host']) : null;

        if ($apiHost !== null) {
            $hosts[] = $apiHost;
        }

        return $hosts;
    }

    public function checkConnection(CourierHttp $http, CourierCredentials $credentials): ConnectionCheck
    {
        $apiHost = $this->apiHostField()->cleanHost((string) $credentials->value('api_host'));

        if ($apiHost === null) {
            return ConnectionCheck::failed('api_host_missing');
        }

        $clientId = $credentials->value('client_id');
        $clientSecret = $credentials->value('client_secret');

        if ($clientId === null || $clientSecret === null) {
            return ConnectionCheck::failed('not_configured');
        }

        try {
            // A token request changes nothing at BOX NOW: a READ in the client's sense (retryable).
            $response = $http->postJson($apiHost, '/api/v1/auth-sessions', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ], HttpCall::read(self::CODE, 'auth_sessions', CallKind::ADMIN));
        } catch (CourierHttpException $e) {
            return ConnectionCheck::failed($e->storedCode());
        }

        if ($response->successful()) {
            // Only the SHAPE is checked; the token is neither kept nor shown.
            $json = $response->json();

            return is_array($json) && isset($json['access_token']) && is_string($json['access_token']) && $json['access_token'] !== ''
                ? ConnectionCheck::ok()
                : ConnectionCheck::failed('invalid_response');
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

    private function apiHostField(): CourierField
    {
        return $this->fields()[0];
    }
}
