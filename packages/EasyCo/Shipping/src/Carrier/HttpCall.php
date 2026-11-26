<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * Describes one outbound call for the client: who it is for, what it is called in the log (a fixed operation name, never
 * a URL), how long it may take, and whether it may be repeated. A READ may be retried by the client; a WRITE never is
 * (courier-integration-design.md §10.2, §6.3). A token request is a POST but a read: it changes nothing at the courier.
 */
final class HttpCall
{
    private function __construct(
        public readonly string $carrierCode,
        public readonly string $operation,
        public readonly CallKind $kind,
        public readonly bool $retryable,
        public readonly string $correlationId,
    ) {
        CarrierValue::nonEmpty(self::class, 'carrierCode', $carrierCode, 64);

        if (preg_match('/^[a-z0-9_.:-]{1,64}$/D', $operation) !== 1) {
            throw InvalidCarrierDataException::field(self::class, 'operation', 'must be a short fixed name');
        }
    }

    public static function read(string $carrierCode, string $operation, CallKind $kind, ?string $correlationId = null): self
    {
        return new self($carrierCode, $operation, $kind, true, $correlationId ?? bin2hex(random_bytes(6)));
    }

    public static function write(string $carrierCode, string $operation, CallKind $kind, ?string $correlationId = null): self
    {
        return new self($carrierCode, $operation, $kind, false, $correlationId ?? bin2hex(random_bytes(6)));
    }
}
