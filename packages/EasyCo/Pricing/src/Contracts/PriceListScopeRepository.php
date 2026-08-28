<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\PriceListScope;

/**
 * Named attach()/detach(), deliberately not save()/delete() — mirrors
 * PriceListScope's own docblock language exactly ("a scope condition is
 * attached or detached... never edited in place").
 */
interface PriceListScopeRepository
{
    public function attach(PriceListScope $scope): void;

    public function detach(string $scopeId): void;

    /** @return PriceListScope[] */
    public function findByPriceListId(string $priceListId): array;
}
