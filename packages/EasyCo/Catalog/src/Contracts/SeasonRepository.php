<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Season;

/**
 * Persistence contract for the global, reusable Season set — identical
 * shape to BrandRepository. Season is not owned by any single Product,
 * so there is no aggregate-wide transaction concern here.
 */
interface SeasonRepository
{
    public function save(Season $season): void;

    public function findById(string $id): ?Season;

    /** @return Season[] */
    public function all(): array;

    /**
     * Count of catalog_products rows referencing this Season via
     * season_id — catalog-domain-design.md §3.13's "is this Season
     * still in use before letting a merchant delete/rename it away"
     * check. A plain count(), not distinct: season_id is a single
     * column on catalog_products, one row per Product.
     */
    public function countProductsUsing(string $seasonId): int;
}
