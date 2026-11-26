<?php

namespace EasyCo\Shipping\Carrier;

/** The result of «Провери връзката»: OK, or a closed error code. Never a response body. */
final class ConnectionCheck
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $code,
    ) {}

    public static function ok(): self
    {
        return new self(true, null);
    }

    /** @param string $code a closed code, e.g. `auth_failed`, `not_configured`, `timeout`; `courier_error:7` carries a courier's integer code */
    public static function failed(string $code): self
    {
        return new self(false, preg_match('/^[a-z0-9_]{1,40}(:\d{1,6})?$/D', $code) === 1 ? $code : 'unknown_error');
    }
}
