<?php

namespace EasyCo\Shipping;

use EasyCo\Shipping\Exceptions\InvalidShippingClassException;
use LogicException;

/**
 * A real entity behind what Variation.shippingClass used to be a free string
 * for — shipping-domain-design.md §3. Mirrors EasyCo\Address\Address's shape:
 * private constructor, a public create() factory, reconstituteFromStorage()
 * for the persistence layer, a one-time assignId().
 *
 * `code` IS IMMUTABLE after construction (no setter) and is NEVER normalized:
 * a Variation will refer to it as a plain value, so rewriting one would
 * silently break that reference. `name` and `description` can change.
 */
final class ShippingClass
{
    public const NAME_MAX_LENGTH = 255;

    private function __construct(
        private ?string $id,
        private string $name,
        private readonly string $code,
        private ?string $description,
    ) {
        if (! ShippingCode::isValid($code)) {
            throw InvalidShippingClassException::invalidCode($code);
        }

        $this->name = self::normalizeName($name);
        $this->description = self::normalizeDescription($description);
    }

    public static function create(string $name, string $code, ?string $description = null): self
    {
        return new self(null, $name, $code, $description);
    }

    /**
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the data
     * is already-valid data read back from storage.
     */
    public static function reconstituteFromStorage(string $id, string $name, string $code, ?string $description): self
    {
        return new self($id, $name, $code, $description);
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidShippingClassException::emptyName();
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidShippingClassException::nameTooLong(self::NAME_MAX_LENGTH);
        }

        return $name;
    }

    private static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);

        return $description === '' ? null : $description;
    }

    public function rename(string $name): void
    {
        $this->name = self::normalizeName($name);
    }

    public function describe(?string $description): void
    {
        $this->description = self::normalizeDescription($description);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ShippingClass already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
