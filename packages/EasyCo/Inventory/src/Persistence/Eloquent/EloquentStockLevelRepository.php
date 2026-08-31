<?php

namespace EasyCo\Inventory\Persistence\Eloquent;

use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
use EasyCo\Inventory\StockLevel;
use InvalidArgumentException;

/** Maps the StockLevel entity onto stock_levels. */
final class EloquentStockLevelRepository implements StockLevelRepository
{
    public function save(StockLevel $stockLevel): void
    {
        // Upserts keyed on variation_id, not the surrogate id — see
        // Contracts\StockLevelRepository::save()'s own docblock and
        // inventory-domain-design.md §8.
        $model = StockLevelModel::updateOrCreate(
            ['variation_id' => $stockLevel->variationId()],
            ['quantity' => $stockLevel->quantity()],
        );

        if ($stockLevel->id() === null) {
            $stockLevel->assignId((string) $model->id);
        }
    }

    public function findByVariationId(string $variationId): StockLevel
    {
        $model = StockLevelModel::where('variation_id', $variationId)->first();

        return $model !== null
            ? StockLevel::reconstituteFromStorage((string) $model->id, (string) $model->variation_id, $model->quantity)
            : StockLevel::forVariation($variationId, 0);
    }

    /**
     * A single atomic conditional UPDATE — the WHERE quantity >= ?
     * guard and the affected-rows check together are what make this
     * race-safe under concurrent calls, not the domain layer's own
     * quantity >= 0 validation (inventory-domain-design.md §6).
     */
    public function decrease(string $variationId, int $amount): void
    {
        self::assertPositiveAmount($amount);

        $affected = StockLevelModel::where('variation_id', $variationId)
            ->where('quantity', '>=', $amount)
            ->decrement('quantity', $amount);

        if ($affected === 0) {
            throw InsufficientStockException::forVariation($variationId, $amount);
        }
    }

    /**
     * firstOrCreate() first, THEN increment() — increment() alone is
     * a silent no-op against zero matched rows, it does not insert
     * (inventory-domain-design.md §7). Both calls are still atomic
     * individually; the two-step shape is what handles "no row yet",
     * not a race-safety concession.
     */
    public function increase(string $variationId, int $amount): void
    {
        self::assertPositiveAmount($amount);

        StockLevelModel::firstOrCreate(['variation_id' => $variationId], ['quantity' => 0]);

        StockLevelModel::where('variation_id', $variationId)->increment('quantity', $amount);
    }

    private static function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive, got {$amount}.");
        }
    }
}
