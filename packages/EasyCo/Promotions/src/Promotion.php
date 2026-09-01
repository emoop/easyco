<?php

namespace EasyCo\Promotions;

use DateTimeImmutable;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionStatus;
use InvalidArgumentException;
use LogicException;

/**
 * A customer-entered discount code — see promotions-domain-design.md §2
 * for the full field list and reasoning. Mirrors EasyCo\Pricing\PriceList's
 * shape: private constructor, named assertion methods, a public create()
 * factory, reconstituteFromStorage() for the persistence layer, and a
 * one-time assignId().
 *
 * No `priority` field, deliberately — see design doc §2/§3.1: nothing
 * in V1 would consume it, since stacking beyond individualUseOnly's
 * binary block is out of scope.
 *
 * WHY percentageBasisPoints IS AN INTEGER, NOT A FLOAT:
 * Same reasoning as PriceList's own field of the same name — 1 basis
 * point = 0.01%, so 2000 = 20%, avoiding float-precision drift.
 */
final class Promotion
{
    private function __construct(
        private ?string $id,
        private string $code,
        private readonly PromotionDiscountType $discountType,
        private readonly ?int $percentageBasisPoints,
        private readonly ?Money $discountAmount,
        private readonly bool $individualUseOnly,
        private readonly bool $excludeSaleItems,
        private readonly ?Money $minimumSpend,
        private readonly ?Money $maximumSpend,
        private readonly bool $newCustomersOnly,
        private readonly ?int $usageLimitTotal,
        private readonly ?int $usageLimitPerCustomer,
        private readonly ?int $usageLimitItems,
        private readonly ?DateTimeImmutable $validFrom,
        private readonly ?DateTimeImmutable $validUntil,
        private PromotionStatus $status,
    ) {
        $this->code = self::normalizeAndValidateCode($code);
        self::assertDiscountValueMatchesType($discountType, $percentageBasisPoints, $discountAmount);
        self::assertValidSpendRange($minimumSpend, $maximumSpend);
        self::assertValidTimeWindow($validFrom, $validUntil);
        self::assertPositiveUsageLimits($usageLimitTotal, $usageLimitPerCustomer, $usageLimitItems);
    }

    /**
     * Lowercased for storage and comparison — same application-layer
     * normalization EasyCo\Account\Account::normalizeAndValidateEmail()
     * uses for email, not a DB-collation trick. EloquentPromotionRepository
     * ::findByCode() lowercases its input the same way, as defense in
     * depth (mirrors EloquentAccountRepository::findByEmail() exactly).
     */
    private static function normalizeAndValidateCode(string $code): string
    {
        $normalized = strtolower(trim($code));

        if ($normalized === '') {
            throw new InvalidArgumentException('Promotion code must not be empty.');
        }

        return $normalized;
    }

    /**
     * A PERCENTAGE Promotion has nothing else to compute a discount
     * from, so a rate is mandatory and a discountAmount would be
     * meaningless; a FIXED_AMOUNT Promotion is the reverse. Exactly one
     * of the two is ever set — same mutual-exclusivity pattern as
     * PriceList::assertPercentageBasisPointsMatchesMode().
     */
    private static function assertDiscountValueMatchesType(
        PromotionDiscountType $discountType,
        ?int $percentageBasisPoints,
        ?Money $discountAmount,
    ): void {
        if ($discountType === PromotionDiscountType::PERCENTAGE) {
            if ($percentageBasisPoints === null) {
                throw new InvalidArgumentException(
                    'A PERCENTAGE Promotion requires a percentageBasisPoints value.'
                );
            }

            if ($percentageBasisPoints < 0) {
                throw new InvalidArgumentException('percentageBasisPoints cannot be negative.');
            }

            if ($percentageBasisPoints > 10000) {
                throw new InvalidArgumentException('percentageBasisPoints cannot exceed 10000 (100%).');
            }

            if ($discountAmount !== null) {
                throw new InvalidArgumentException(
                    'A PERCENTAGE Promotion must not have a discountAmount value.'
                );
            }

            return;
        }

        if ($discountAmount === null) {
            throw new InvalidArgumentException(
                'A FIXED_AMOUNT Promotion requires a discountAmount value.'
            );
        }

        if ($percentageBasisPoints !== null) {
            throw new InvalidArgumentException(
                'A FIXED_AMOUNT Promotion must not have a percentageBasisPoints value.'
            );
        }
    }

    private static function assertValidSpendRange(?Money $minimumSpend, ?Money $maximumSpend): void
    {
        if ($minimumSpend === null || $maximumSpend === null) {
            return;
        }

        if (! $maximumSpend->subtract($minimumSpend)->isPositive()) {
            throw new InvalidArgumentException('maximumSpend must be strictly greater than minimumSpend.');
        }
    }

    private static function assertValidTimeWindow(?DateTimeImmutable $validFrom, ?DateTimeImmutable $validUntil): void
    {
        if ($validFrom !== null && $validUntil !== null && $validUntil <= $validFrom) {
            throw new InvalidArgumentException('validUntil must be strictly after validFrom.');
        }
    }

    private static function assertPositiveUsageLimits(
        ?int $usageLimitTotal,
        ?int $usageLimitPerCustomer,
        ?int $usageLimitItems,
    ): void {
        foreach ([
            'usageLimitTotal' => $usageLimitTotal,
            'usageLimitPerCustomer' => $usageLimitPerCustomer,
            'usageLimitItems' => $usageLimitItems,
        ] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("Promotion {$name} must be a positive integer, got {$value}.");
            }
        }
    }

    /**
     * The only path regular, merchant-facing code may use to create a
     * Promotion — always status: ACTIVE.
     */
    public static function create(
        string $code,
        PromotionDiscountType $discountType,
        ?int $percentageBasisPoints = null,
        ?Money $discountAmount = null,
        bool $individualUseOnly = false,
        bool $excludeSaleItems = false,
        ?Money $minimumSpend = null,
        ?Money $maximumSpend = null,
        bool $newCustomersOnly = false,
        ?int $usageLimitTotal = null,
        ?int $usageLimitPerCustomer = null,
        ?int $usageLimitItems = null,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ): self {
        return new self(
            id: null,
            code: $code,
            discountType: $discountType,
            percentageBasisPoints: $percentageBasisPoints,
            discountAmount: $discountAmount,
            individualUseOnly: $individualUseOnly,
            excludeSaleItems: $excludeSaleItems,
            minimumSpend: $minimumSpend,
            maximumSpend: $maximumSpend,
            newCustomersOnly: $newCustomersOnly,
            usageLimitTotal: $usageLimitTotal,
            usageLimitPerCustomer: $usageLimitPerCustomer,
            usageLimitItems: $usageLimitItems,
            validFrom: $validFrom,
            validUntil: $validUntil,
            status: PromotionStatus::ACTIVE,
        );
    }

    /**
     * Reconstitutes a Promotion exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $code,
        PromotionDiscountType $discountType,
        ?int $percentageBasisPoints,
        ?Money $discountAmount,
        bool $individualUseOnly,
        bool $excludeSaleItems,
        ?Money $minimumSpend,
        ?Money $maximumSpend,
        bool $newCustomersOnly,
        ?int $usageLimitTotal,
        ?int $usageLimitPerCustomer,
        ?int $usageLimitItems,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        PromotionStatus $status,
    ): self {
        return new self(
            id: $id,
            code: $code,
            discountType: $discountType,
            percentageBasisPoints: $percentageBasisPoints,
            discountAmount: $discountAmount,
            individualUseOnly: $individualUseOnly,
            excludeSaleItems: $excludeSaleItems,
            minimumSpend: $minimumSpend,
            maximumSpend: $maximumSpend,
            newCustomersOnly: $newCustomersOnly,
            usageLimitTotal: $usageLimitTotal,
            usageLimitPerCustomer: $usageLimitPerCustomer,
            usageLimitItems: $usageLimitItems,
            validFrom: $validFrom,
            validUntil: $validUntil,
            status: $status,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Promotion already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function discountType(): PromotionDiscountType
    {
        return $this->discountType;
    }

    public function percentageBasisPoints(): ?int
    {
        return $this->percentageBasisPoints;
    }

    public function discountAmount(): ?Money
    {
        return $this->discountAmount;
    }

    public function individualUseOnly(): bool
    {
        return $this->individualUseOnly;
    }

    public function excludeSaleItems(): bool
    {
        return $this->excludeSaleItems;
    }

    public function minimumSpend(): ?Money
    {
        return $this->minimumSpend;
    }

    public function maximumSpend(): ?Money
    {
        return $this->maximumSpend;
    }

    public function newCustomersOnly(): bool
    {
        return $this->newCustomersOnly;
    }

    public function usageLimitTotal(): ?int
    {
        return $this->usageLimitTotal;
    }

    public function usageLimitPerCustomer(): ?int
    {
        return $this->usageLimitPerCustomer;
    }

    public function usageLimitItems(): ?int
    {
        return $this->usageLimitItems;
    }

    public function validFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function status(): PromotionStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === PromotionStatus::ACTIVE;
    }

    /**
     * True if $at falls within [validFrom, validUntil] — both boundaries
     * inclusive, null on either side meaning unbounded in that
     * direction. Mirrors PriceList::isValidAt() exactly.
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

    public function activate(): void
    {
        $this->status = PromotionStatus::ACTIVE;
    }

    public function deactivate(): void
    {
        $this->status = PromotionStatus::INACTIVE;
    }
}
