<?php

namespace EasyCo\Inventory;

use InvalidArgumentException;
use LogicException;

/**
 * The StockLevel entity — how many units of one Variation are
 * currently in stock. See inventory-domain-design.md §2 for why the
 * entity is named StockLevel while the package is Inventory (mirrors
 * Media/MediaAsset's package-name-vs-entity-name split).
 *
 * Unlike SaleLine (append-only, never mutated in place), StockLevel
 * represents genuinely current, mutable state — setQuantity() is a
 * legitimate business operation, not a violation of any
 * historical-record rule.
 */
final class StockLevel
{
    public function __construct(
        private ?string $id,
        private readonly string $variationId,
        private int $quantity,
    ) {
        if ($variationId === '') {
            throw new InvalidArgumentException('StockLevel variationId must not be empty.');
        }

        self::assertValidQuantity($quantity);
    }

    public static function forVariation(string $variationId, int $quantity = 0): self
    {
        return new self(id: null, variationId: $variationId, quantity: $quantity);
    }

    /**
     * Reconstitutes a StockLevel exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that
     * the given data is already-valid data read back from storage.
     * This method is not a business operation and application code
     * must never call it directly; only a repository implementation
     * reconstructing this entity from an already-validated row should
     * call it.
     */
    public static function reconstituteFromStorage(string $id, string $variationId, int $quantity): self
    {
        return new self(id: $id, variationId: $variationId, quantity: $quantity);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('StockLevel already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function variationId(): string
    {
        return $this->variationId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    /**
     * The merchant's "set absolute quantity" operation — an
     * authoritative overwrite, not a delta. See
     * Contracts\StockLevelRepository::increase()/decrease() for the
     * separate, atomic delta operations this is NOT a substitute for
     * (inventory-domain-design.md §8).
     */
    public function setQuantity(int $quantity): void
    {
        self::assertValidQuantity($quantity);
        $this->quantity = $quantity;
    }

    private static function assertValidQuantity(int $quantity): void
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException("StockLevel quantity cannot be negative, got {$quantity}.");
        }
    }
}
