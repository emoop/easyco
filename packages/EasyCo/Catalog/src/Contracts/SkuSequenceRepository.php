<?php

namespace EasyCo\Catalog\Contracts;

/**
 * Persistence contract for the persistent, concurrency-safe sequence
 * backing auto-generated Product::baseSku() values (the
 * 'catalog.product.base_sku' Hook filter —
 * App\Providers\CatalogSkuGeneratorServiceProvider). Deliberately
 * separate from ProductRepository: this isn't part of the Product
 * aggregate itself, it's infrastructure the base_sku generator depends
 * on.
 */
interface SkuSequenceRepository
{
    /**
     * Atomically increments and returns the next sequence value. Two
     * calls — even from concurrent requests — can never return the same
     * value; see EloquentSkuSequenceRepository::next() for how that
     * guarantee is actually implemented.
     */
    public function next(): int;
}
