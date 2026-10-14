<?php

namespace App\Services;

use App\Enums\DeviceType;

/**
 * The device class of a user agent (order-context-design.md 2.1): DERIVED by the server from the header, never a classification a client
 * asserts, and deliberately coarse (a class, not a fingerprint). No user agent at all is `unknown`.
 */
final class DeviceTypeDetector
{
    public function detect(?string $userAgent): DeviceType
    {
        $agent = $userAgent === null ? '' : strtolower(trim($userAgent));

        if ($agent === '') {
            return DeviceType::UNKNOWN;
        }

        if (preg_match('/bot|crawl|spider|slurp|curl\/|wget|python-requests|headlesschrome|facebookexternalhit/', $agent) === 1) {
            return DeviceType::BOT;
        }

        if (preg_match('/ipad|tablet|kindle|silk\/|playbook/', $agent) === 1 || (str_contains($agent, 'android') && ! str_contains($agent, 'mobile'))) {
            return DeviceType::TABLET;
        }

        if (preg_match('/mobi|iphone|ipod|android|windows phone|blackberry/', $agent) === 1) {
            return DeviceType::MOBILE;
        }

        return DeviceType::DESKTOP;
    }
}
