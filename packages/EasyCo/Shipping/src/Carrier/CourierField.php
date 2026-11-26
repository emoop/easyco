<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * One setting a courier needs from the merchant (courier-integration-design.md §5), declared by its package so the
 * «Куриери» page renders whatever it declares and core lists nothing.
 *
 * `perEnvironment`: stored under separate test / production keys, so switching the environment never sends one
 * environment's credentials to the other (§10.1). A SECRET is write-only and encrypted; a HOST is a bare domain name
 * that must be on the declared suffix allowlist (it feeds the outbound allowlist, so it is checked on save AND on read).
 */
final class CourierField
{
    public const TEXT = 'text';

    public const SECRET = 'secret';

    public const HOST = 'host';

    /**
     * @param  string  $name  [a-z0-9_]
     * @param  string  $label  a lang key under `couriers.` (e.g. `boxnow.client_id`)
     * @param  list<string>  $hostSuffixes  HOST only: registrable domains the value must equal or sit under
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $kind = self::TEXT,
        public readonly bool $perEnvironment = true,
        public readonly int $max = 120,
        public readonly array $hostSuffixes = [],
    ) {
        if (preg_match('/^[a-z0-9_]{1,40}$/D', $name) !== 1) {
            throw InvalidCarrierDataException::field(self::class, 'name', 'must be lowercase letters, digits and underscores');
        }

        if (! in_array($kind, [self::TEXT, self::SECRET, self::HOST], true)) {
            throw InvalidCarrierDataException::field(self::class, 'kind', 'unknown kind');
        }

        if ($kind === self::HOST && $hostSuffixes === []) {
            throw InvalidCarrierDataException::field(self::class, 'hostSuffixes', 'a host field needs an allowlist');
        }
    }

    public function isSecret(): bool
    {
        return $this->kind === self::SECRET;
    }

    /** The cleaned host when $value is a bare hostname on this field's allowlist, else null. */
    public function cleanHost(string $value): ?string
    {
        return AllowedHost::clean($value, $this->hostSuffixes);
    }
}
