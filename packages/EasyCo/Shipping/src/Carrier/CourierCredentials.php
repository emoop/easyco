<?php

namespace EasyCo\Shipping\Carrier;

use LogicException;
use WeakMap;

/**
 * What an adapter needs to talk to one courier in one environment (courier-integration-design.md §2.2, §10.1).
 *
 * NEVER PRINTABLE. The values include secrets, so nothing that formats, dumps or serialises this object shows a value:
 * __toString / __debugInfo hide everything, there is no public property, and serialisation is refused (a queue payload
 * must carry the carrier code and read the vault inside the job). The only way to a value is value($name), called by the
 * adapter at the moment it builds a request.
 *
 * The values are NOT properties of the object: they live in a static WeakMap keyed by the object, so var_export(), print_r(),
 * var_dump(), get_object_vars() and an (array) cast cannot reach them either (a test pins the dumps). They go with the object.
 */
final class CourierCredentials
{
    /** @var WeakMap<self, array<string, string>>|null */
    private static ?WeakMap $vault = null;

    /** @param array<string, string> $values field name => value, secrets included */
    public function __construct(
        private readonly string $carrierCode,
        private readonly CourierEnvironment $environment,
        array $values,
    ) {
        self::$vault ??= new WeakMap;
        self::$vault[$this] = $values;
    }

    public function carrierCode(): string
    {
        return $this->carrierCode;
    }

    public function environment(): CourierEnvironment
    {
        return $this->environment;
    }

    public function value(string $name): ?string
    {
        $value = (self::$vault[$this] ?? [])[$name] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function has(string $name): bool
    {
        return $this->value($name) !== null;
    }

    public function __toString(): string
    {
        return '[courier credentials hidden]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credentials' => 'hidden'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Courier credentials are never serialised.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Courier credentials are never serialised.');
    }
}
