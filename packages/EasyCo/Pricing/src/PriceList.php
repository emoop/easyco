<?php

namespace EasyCo\Pricing;

use DateTimeImmutable;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListStatus;
use EasyCo\Pricing\Exceptions\CannotModifySystemPriceListException;
use InvalidArgumentException;
use LogicException;

/**
 * A named, prioritized pricing rule — see
 * pricing-persistence-domain-design.md §3/§4.1/§4.5 for the full design.
 * Scope (which products/variations it applies to, §4.1) and fixed-price
 * items (§4.3) are separate concepts, not modeled on this class — this
 * is deliberately just the PriceList aggregate root itself.
 *
 * WHY percentageBasisPoints IS AN INTEGER, NOT A FLOAT:
 * Same reasoning as `Price`'s own tax rate (see Price.php's docblock) —
 * 1 basis point = 0.01%, so 2000 = 20%. Keeping the rate as an integer
 * avoids float-precision drift when it's later multiplied against a
 * Money amount to compute a discount.
 */
final class PriceList
{
    private function __construct(
        private ?string $id,
        private string $name,
        private readonly PriceListMode $mode,
        private readonly ?int $percentageBasisPoints,
        private readonly int $priority,
        private readonly ?DateTimeImmutable $validFrom,
        private readonly ?DateTimeImmutable $validUntil,
        private PriceListStatus $status,
        private readonly bool $isSystem,
    ) {
        self::assertValidName($name);
        self::assertPercentageBasisPointsMatchesMode($mode, $percentageBasisPoints);
        self::assertValidTimeWindow($validFrom, $validUntil);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('PriceList name must not be empty.');
        }
    }

    /**
     * A PERCENTAGE_OFF_REGULAR list has nothing else to compute a price
     * from, so a rate is mandatory; a FIXED_ITEMS list's prices come
     * entirely from its PriceListItem rows (not modeled on this class —
     * see the class docblock), so a rate here would be meaningless and
     * is rejected rather than silently ignored.
     */
    private static function assertPercentageBasisPointsMatchesMode(
        PriceListMode $mode,
        ?int $percentageBasisPoints
    ): void {
        if ($mode === PriceListMode::PERCENTAGE_OFF_REGULAR) {
            if ($percentageBasisPoints === null) {
                throw new InvalidArgumentException(
                    'A PERCENTAGE_OFF_REGULAR PriceList requires a percentageBasisPoints value.'
                );
            }

            if ($percentageBasisPoints < 0) {
                throw new InvalidArgumentException('percentageBasisPoints cannot be negative.');
            }

            return;
        }

        if ($percentageBasisPoints !== null) {
            throw new InvalidArgumentException(
                'A FIXED_ITEMS PriceList must not have a percentageBasisPoints value — '.
                'its prices come from PriceListItem rows instead.'
            );
        }
    }

    private static function assertValidTimeWindow(?DateTimeImmutable $validFrom, ?DateTimeImmutable $validUntil): void
    {
        if ($validFrom !== null && $validUntil !== null && $validUntil <= $validFrom) {
            throw new InvalidArgumentException('validUntil must be strictly after validFrom.');
        }
    }

    /**
     * The only path regular, merchant-facing code may use to create a
     * PriceList — always isSystem: false, status: ACTIVE. It is
     * structurally impossible to produce an isSystem=true list through
     * this factory; see createSystemList() for the one other path.
     */
    public static function create(
        string $name,
        PriceListMode $mode,
        int $priority,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        ?int $percentageBasisPoints = null,
    ): self {
        return new self(
            id: null,
            name: $name,
            mode: $mode,
            percentageBasisPoints: $percentageBasisPoints,
            priority: $priority,
            validFrom: $validFrom,
            validUntil: $validUntil,
            status: PriceListStatus::ACTIVE,
            isSystem: false,
        );
    }

    /**
     * PERSISTENCE/SEEDING-LAYER ONLY — mirrors the posture of
     * reconstituteFromStorage() below, not a business operation regular
     * application code should ever reach for. Creates one of the two
     * reserved system PriceLists ("Regular Prices", "Manual Sale" — §4.5)
     * that must exist exactly once per store. Only the one-time,
     * per-store seeding mechanism (§4.5, §8 item 3 — not yet implemented)
     * should call this.
     *
     * System lists never have a time limit, by design (§4.5) —
     * validFrom/validUntil are always null and aren't even accepted as
     * parameters here, so it is structurally impossible to construct a
     * time-boxed system list through this factory.
     */
    public static function createSystemList(
        string $name,
        PriceListMode $mode,
        int $priority,
        ?int $percentageBasisPoints = null,
    ): self {
        return new self(
            id: null,
            name: $name,
            mode: $mode,
            percentageBasisPoints: $percentageBasisPoints,
            priority: $priority,
            validFrom: null,
            validUntil: null,
            status: PriceListStatus::ACTIVE,
            isSystem: true,
        );
    }

    /**
     * Reconstitutes a PriceList exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $name,
        PriceListMode $mode,
        int $priority,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        ?int $percentageBasisPoints,
        PriceListStatus $status,
        bool $isSystem,
    ): self {
        return new self(
            id: $id,
            name: $name,
            mode: $mode,
            percentageBasisPoints: $percentageBasisPoints,
            priority: $priority,
            validFrom: $validFrom,
            validUntil: $validUntil,
            status: $status,
            isSystem: $isSystem,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PriceList already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function mode(): PriceListMode
    {
        return $this->mode;
    }

    public function percentageBasisPoints(): ?int
    {
        return $this->percentageBasisPoints;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function validFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function status(): PriceListStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === PriceListStatus::ACTIVE;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    /**
     * True if $at falls within [validFrom, validUntil] — both boundaries
     * inclusive (a list is valid at the exact instant it starts and the
     * exact instant it ends), null on either side meaning unbounded in
     * that direction. This is a query the future resolver's time-window
     * filter (§4.6 step 1) will need, but it belongs on the entity
     * itself since it's purely a function of the entity's own state.
     */
    public function isValidAt(DateTimeImmutable $at): bool
    {
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }

        return true;
    }

    /**
     * Subject to the same validation as construction (assertValidName()).
     * Unlike Client::changeName(), this does not short-circuit on an
     * unchanged value — the isSystem guard below must fire unconditionally
     * for a system list, even a no-op-looking rename to its own current
     * name.
     */
    public function rename(string $newName): void
    {
        if ($this->isSystem) {
            throw CannotModifySystemPriceListException::cannotRename($this->id ?? '(unassigned)');
        }

        self::assertValidName($newName);
        $this->name = $newName;
    }

    /**
     * Always allowed, even for a system list — unlike rename()/
     * deactivate(), there is no invariant to protect here: system lists
     * start ACTIVE already (§4.5), and if some other path ever left one
     * INACTIVE, there is no reason to block turning it back on.
     */
    public function activate(): void
    {
        $this->status = PriceListStatus::ACTIVE;
    }

    public function deactivate(): void
    {
        if ($this->isSystem) {
            throw CannotModifySystemPriceListException::cannotDeactivate($this->id ?? '(unassigned)');
        }

        $this->status = PriceListStatus::INACTIVE;
    }
}
