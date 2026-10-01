<?php

namespace EasyCo\Shipping;

use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use LogicException;

/**
 * What the customer actually picks — shipping-domain-design.md §5. STORE ONLY
 * IN THIS STAGE: the invariants below are enforced, but nothing here
 * calculates a rate (stage 3) or calls a carrier (the provider contracts).
 *
 * THE INVARIANTS, per kind (money is always an integer in minor units and
 * never negative):
 *  - FLAT:      amountMinor required; no classRates; no carrierCode.
 *  - FREE:      no amountMinor, no classRates, no carrierCode, and no
 *               freeAboveMinor — a threshold on something already free is
 *               contradictory, so it is refused rather than ignored.
 *  - PER_CLASS: amountMinor required (the fallback for an item with no class or
 *               no rate for its class); classRates may be empty and a rate of 0
 *               is valid; no carrierCode.
 *  - CARRIER:   carrierCode required; no amountMinor; no classRates.
 * freeAboveMinor is allowed on FLAT, PER_CLASS and CARRIER (its semantics are
 * stage 3's). requiresPickupPoint is allowed on any kind. CARRIER methods can
 * be CONSTRUCTED; the resolver's behaviour for them comes later.
 *
 * classRates keys use the same code format as a ShippingClass code. NOTE: PHP
 * turns a numeric-looking array key such as "123" into an int, so code that
 * iterates classRates() and needs a string must cast the key.
 *
 * zoneId is a structural reference (the zone the method is offered in) and is
 * not rewritten by update().
 */
final class ShippingMethod
{
    public const NAME_MAX_LENGTH = 255;

    private string $name;

    private ShippingMethodKind $kind;

    private int $sortOrder;

    private bool $isActive;

    private ?int $amountMinor;

    /** @var array<string, int> */
    private array $classRates;

    private ?int $freeAboveMinor;

    private ?string $carrierCode;

    private bool $requiresPickupPoint;

    /** @param array<string, int> $classRates */
    private function __construct(
        private ?string $id,
        private readonly string $zoneId,
        string $name,
        ShippingMethodKind $kind,
        int $sortOrder,
        bool $isActive,
        ?int $amountMinor,
        array $classRates,
        ?int $freeAboveMinor,
        ?string $carrierCode,
        bool $requiresPickupPoint,
    ) {
        if (trim($zoneId) === '') {
            throw InvalidShippingMethodException::emptyZoneId();
        }

        $this->apply($name, $kind, $sortOrder, $isActive, $amountMinor, $classRates, $freeAboveMinor, $carrierCode, $requiresPickupPoint);
    }

    /** @param array<string, int> $classRates */
    public static function create(
        string $zoneId,
        string $name,
        ShippingMethodKind $kind,
        int $sortOrder = 0,
        bool $isActive = true,
        ?int $amountMinor = null,
        array $classRates = [],
        ?int $freeAboveMinor = null,
        ?string $carrierCode = null,
        bool $requiresPickupPoint = false,
    ): self {
        return new self(null, $zoneId, $name, $kind, $sortOrder, $isActive, $amountMinor, $classRates, $freeAboveMinor, $carrierCode, $requiresPickupPoint);
    }

    /**
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the data
     * is already-valid data read back from storage.
     *
     * @param array<string, int> $classRates
     */
    public static function reconstituteFromStorage(
        string $id,
        string $zoneId,
        string $name,
        ShippingMethodKind $kind,
        int $sortOrder,
        bool $isActive,
        ?int $amountMinor,
        array $classRates,
        ?int $freeAboveMinor,
        ?string $carrierCode,
        bool $requiresPickupPoint,
    ): self {
        return new self($id, $zoneId, $name, $kind, $sortOrder, $isActive, $amountMinor, $classRates, $freeAboveMinor, $carrierCode, $requiresPickupPoint);
    }

    /**
     * Rewrites every editable field (kind included), re-running the
     * construction-time validation. Everything is validated before anything is
     * assigned, so a rejected update leaves the entity as it was.
     *
     * @param array<string, int> $classRates
     */
    public function update(
        string $name,
        ShippingMethodKind $kind,
        int $sortOrder,
        bool $isActive,
        ?int $amountMinor,
        array $classRates,
        ?int $freeAboveMinor,
        ?string $carrierCode,
        bool $requiresPickupPoint,
    ): void {
        $this->apply($name, $kind, $sortOrder, $isActive, $amountMinor, $classRates, $freeAboveMinor, $carrierCode, $requiresPickupPoint);
    }

    /** @param array<mixed> $classRates */
    private function apply(
        string $name,
        ShippingMethodKind $kind,
        int $sortOrder,
        bool $isActive,
        ?int $amountMinor,
        array $classRates,
        ?int $freeAboveMinor,
        ?string $carrierCode,
        bool $requiresPickupPoint,
    ): void {
        $name = trim($name);

        if ($name === '') {
            throw InvalidShippingMethodException::emptyName();
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidShippingMethodException::nameTooLong(self::NAME_MAX_LENGTH);
        }

        if ($sortOrder < 0) {
            throw InvalidShippingMethodException::negativeSortOrder($sortOrder);
        }

        if ($amountMinor !== null && $amountMinor < 0) {
            throw InvalidShippingMethodException::negativeAmount('amountMinor', $amountMinor);
        }

        if ($freeAboveMinor !== null && $freeAboveMinor < 0) {
            throw InvalidShippingMethodException::negativeAmount('freeAboveMinor', $freeAboveMinor);
        }

        $classRates = self::normalizeClassRates($classRates);

        if ($carrierCode !== null && ! ShippingCode::isValid($carrierCode)) {
            throw InvalidShippingMethodException::invalidCarrierCode($carrierCode);
        }

        self::assertKindInvariants($kind, $amountMinor, $classRates, $freeAboveMinor, $carrierCode);

        $this->name = $name;
        $this->kind = $kind;
        $this->sortOrder = $sortOrder;
        $this->isActive = $isActive;
        $this->amountMinor = $amountMinor;
        $this->classRates = $classRates;
        $this->freeAboveMinor = $freeAboveMinor;
        $this->carrierCode = $carrierCode;
        $this->requiresPickupPoint = $requiresPickupPoint;
    }

    /**
     * @param  array<mixed>  $classRates
     * @return array<string, int>
     */
    private static function normalizeClassRates(array $classRates): array
    {
        foreach ($classRates as $classCode => $amount) {
            if (! ShippingCode::isValid((string) $classCode)) {
                throw InvalidShippingMethodException::invalidClassRateCode($classCode);
            }

            if (! is_int($amount) || $amount < 0) {
                throw InvalidShippingMethodException::invalidClassRate((string) $classCode, $amount);
            }
        }

        // Deterministic order, so equal rate sets compare and persist equal.
        ksort($classRates, SORT_STRING);

        return $classRates;
    }

    /** @param array<string, int> $classRates */
    private static function assertKindInvariants(
        ShippingMethodKind $kind,
        ?int $amountMinor,
        array $classRates,
        ?int $freeAboveMinor,
        ?string $carrierCode,
    ): void {
        if ($kind !== ShippingMethodKind::PER_CLASS && $classRates !== []) {
            throw InvalidShippingMethodException::classRatesNotAllowed($kind);
        }

        if ($kind !== ShippingMethodKind::CARRIER && $carrierCode !== null) {
            throw InvalidShippingMethodException::carrierCodeNotAllowed($kind);
        }

        switch ($kind) {
            case ShippingMethodKind::FLAT:
            case ShippingMethodKind::PER_CLASS:
                if ($amountMinor === null) {
                    throw InvalidShippingMethodException::amountRequired($kind);
                }

                return;

            case ShippingMethodKind::FREE:
                if ($amountMinor !== null) {
                    throw InvalidShippingMethodException::amountNotAllowed($kind);
                }

                if ($freeAboveMinor !== null) {
                    throw InvalidShippingMethodException::freeAboveNotAllowed($kind);
                }

                return;

            case ShippingMethodKind::CARRIER:
                if ($carrierCode === null) {
                    throw InvalidShippingMethodException::carrierCodeRequired();
                }

                if ($amountMinor !== null) {
                    throw InvalidShippingMethodException::amountNotAllowed($kind);
                }

                return;
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ShippingMethod already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function zoneId(): string
    {
        return $this->zoneId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): ShippingMethodKind
    {
        return $this->kind;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function amountMinor(): ?int
    {
        return $this->amountMinor;
    }

    /** @return array<string, int> class code => amount in minor units */
    public function classRates(): array
    {
        return $this->classRates;
    }

    public function freeAboveMinor(): ?int
    {
        return $this->freeAboveMinor;
    }

    public function carrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function requiresPickupPoint(): bool
    {
        return $this->requiresPickupPoint;
    }
}
