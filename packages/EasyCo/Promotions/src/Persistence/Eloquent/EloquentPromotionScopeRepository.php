<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\PromotionScope;
use InvalidArgumentException;

/**
 * Maps the PromotionScope entity onto `promotion_scopes`. Unlike
 * EloquentPriceListScopeRepository, attach()/detach() here don't own a
 * derived signature column on the parent row — Promotions has no
 * equivalent of pricing_price_lists.scope_signature (no
 * (priority, scope_signature) uniqueness concept — promotions-domain-
 * design.md §2/§3.1 explicitly has no priority field), so there is
 * nothing to recompute on the parent after an attach/detach.
 */
final class EloquentPromotionScopeRepository implements PromotionScopeRepository
{
    public function attach(PromotionScope $scope): void
    {
        if ($scope->promotionId() === '') {
            throw new InvalidArgumentException(
                'PromotionScope has no promotionId yet — call assignPromotionId() on it '.
                '(after the owning Promotion itself has been persisted) before attach().'
            );
        }

        $model = new PromotionScopeModel([
            'promotion_id' => $scope->promotionId(),
            'scope_type' => $scope->scopeType()->value,
            'scope_reference_id' => $scope->scopeReferenceId(),
            'mode' => $scope->mode()->value,
        ]);
        $model->save();

        $scope->assignId((string) $model->id);
    }

    public function detach(string $scopeId): void
    {
        PromotionScopeModel::findOrFail($scopeId)->delete();
    }

    /** @return PromotionScope[] */
    public function findByPromotionId(string $promotionId): array
    {
        return PromotionScopeModel::where('promotion_id', $promotionId)
            ->get()
            ->map(fn (PromotionScopeModel $model) => $this->toDomainScope($model))
            ->all();
    }

    private function toDomainScope(PromotionScopeModel $model): PromotionScope
    {
        return PromotionScope::reconstituteFromStorage(
            id: (string) $model->id,
            promotionId: (string) $model->promotion_id,
            scopeType: PromotionScopeType::from($model->scope_type),
            scopeReferenceId: $model->scope_reference_id,
            mode: PromotionScopeMode::from($model->mode),
        );
    }
}
