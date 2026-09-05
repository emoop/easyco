<?php

namespace EasyCo\Promotions;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * A permanent, historical record that a specific Order actually used a
 * specific Promotion — see promotions-domain-design.md §5 ("a permanent,
 * historical fact — order placement") and checkout-domain-design.md §7.
 *
 * NO assignPromotionId() BACKFILL METHOD — unlike PromotionScope, a
 * PromotionRedemption is only ever created once its Promotion AND its
 * Order both already have real ids (it is written at order-placement
 * time, per checkout-domain-design.md §7). There is no placeholder-id
 * scenario to backfill from here; do not "helpfully" add one by copying
 * PromotionScope's shape uncritically.
 *
 * accountId IS NULLABLE, DELIBERATELY — a guest redemption (accountId:
 * null) never counts toward usage_limit_per_customer, the identical
 * reasoning promotions-domain-design.md §2's new_customers_only already
 * uses for guests.
 */
final class PromotionRedemption
{
    public function __construct(
        private ?string $id,
        private string $promotionId,
        private readonly string $orderId,
        private readonly ?string $accountId,
        private readonly DateTimeImmutable $redeemedAt,
    ) {
        self::assertNotEmpty('promotionId', $promotionId);
        self::assertNotEmpty('orderId', $orderId);
        self::assertAccountIdNotEmptyString($accountId);
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("PromotionRedemption {$fieldName} must not be empty.");
        }
    }

    private static function assertAccountIdNotEmptyString(?string $accountId): void
    {
        if ($accountId === '') {
            throw new InvalidArgumentException('PromotionRedemption accountId must not be an empty string; use null for a guest redemption.');
        }
    }

    /**
     * Reconstitutes a PromotionRedemption exactly as it exists in
     * storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $promotionId,
        string $orderId,
        ?string $accountId,
        DateTimeImmutable $redeemedAt,
    ): self {
        return new self(
            id: $id,
            promotionId: $promotionId,
            orderId: $orderId,
            accountId: $accountId,
            redeemedAt: $redeemedAt,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PromotionRedemption already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function promotionId(): string
    {
        return $this->promotionId;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function redeemedAt(): DateTimeImmutable
    {
        return $this->redeemedAt;
    }
}
