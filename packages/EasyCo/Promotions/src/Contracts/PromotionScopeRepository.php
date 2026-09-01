<?php

namespace EasyCo\Promotions\Contracts;

use EasyCo\Promotions\PromotionScope;

/**
 * Named attach()/detach(), deliberately not save()/delete() — mirrors
 * EasyCo\Pricing\Contracts\PriceListScopeRepository's own docblock
 * language exactly ("a scope condition is attached or detached...
 * never edited in place").
 */
interface PromotionScopeRepository
{
    public function attach(PromotionScope $scope): void;

    public function detach(string $scopeId): void;

    /** @return PromotionScope[] */
    public function findByPromotionId(string $promotionId): array;
}
