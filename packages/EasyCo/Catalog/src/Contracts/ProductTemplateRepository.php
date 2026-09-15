<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\ProductTemplate;

/**
 * Persistence contract for the global, reusable ProductTemplate set —
 * identical minimal shape to SeasonRepository/ProductGroupRepository.
 */
interface ProductTemplateRepository
{
    public function save(ProductTemplate $productTemplate): void;

    public function findById(string $id): ?ProductTemplate;

    /** @return ProductTemplate[] */
    public function all(): array;
}
