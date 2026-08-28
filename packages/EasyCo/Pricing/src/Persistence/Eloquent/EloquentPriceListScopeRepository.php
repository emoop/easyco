<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\PriceListSignature;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Maps the PriceListScope entity onto pricing_price_list_scopes.
 *
 * OWNS pricing_price_lists.scope_signature: attach()/detach() are the
 * only two places in this package that ever recompute and write that
 * column — see EloquentPriceListRepository::save()'s own docblock for
 * why save() itself deliberately never touches it. Both methods wrap
 * their row change plus the signature recompute in one DB::transaction()
 * so the two can never observably drift apart (e.g. a crash between the
 * insert and the signature update leaving a stale signature behind).
 */
final class EloquentPriceListScopeRepository implements PriceListScopeRepository
{
    public function attach(PriceListScope $scope): void
    {
        if ($scope->priceListId() === '') {
            throw new InvalidArgumentException(
                'PriceListScope has no priceListId yet — call assignPriceListId() on it '.
                '(after the owning PriceList itself has been persisted) before attach().'
            );
        }

        DB::transaction(function () use ($scope): void {
            $model = new PriceListScopeModel([
                'price_list_id' => $scope->priceListId(),
                'scope_type' => $scope->scopeType()->value,
                'scope_reference_id' => $scope->scopeReferenceId(),
            ]);
            $model->save();

            $scope->assignId((string) $model->id);

            $this->recalculateSignature($scope->priceListId());
        });
    }

    public function detach(string $scopeId): void
    {
        DB::transaction(function () use ($scopeId): void {
            $model = PriceListScopeModel::findOrFail($scopeId);
            $priceListId = (string) $model->price_list_id;

            $model->delete();

            // If that was the last remaining scope, findByPriceListId()
            // below returns [] and PriceListSignature::forScopes([])
            // already resolves to forUniversalScope() on its own — no
            // special case needed here, per that class's own docblock.
            $this->recalculateSignature($priceListId);
        });
    }

    /** @return PriceListScope[] */
    public function findByPriceListId(string $priceListId): array
    {
        return PriceListScopeModel::where('price_list_id', $priceListId)
            ->get()
            ->map(fn (PriceListScopeModel $model) => $this->toDomainScope($model))
            ->all();
    }

    private function recalculateSignature(string $priceListId): void
    {
        $currentScopes = $this->findByPriceListId($priceListId);
        $signature = PriceListSignature::forScopes($currentScopes);

        PriceListModel::where('id', $priceListId)->update([
            'scope_signature' => $signature->value(),
        ]);
    }

    private function toDomainScope(PriceListScopeModel $model): PriceListScope
    {
        return PriceListScope::reconstituteFromStorage(
            id: (string) $model->id,
            priceListId: (string) $model->price_list_id,
            scopeType: PriceListScopeType::from($model->scope_type),
            scopeReferenceId: $model->scope_reference_id,
        );
    }
}
