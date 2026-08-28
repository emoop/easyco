<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListStatus;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListSignature;

/**
 * Maps the PriceList entity onto pricing_price_lists. Variant B: this
 * repository never touches pricing_price_list_scopes/
 * pricing_price_list_items directly — those are
 * EloquentPriceListScopeRepository's/EloquentPriceListItemRepository's
 * own concern.
 *
 * scope_signature IS SET HERE ONLY ON INSERT, NEVER ON UPDATE — a
 * brand-new list always starts with zero scopes (PriceListSignature::
 * forUniversalScope()), but once a list exists, its signature is
 * exclusively owned and recomputed by
 * EloquentPriceListScopeRepository::attach()/detach() (the only place
 * that ever has visibility into the list's full, current scope set).
 * save() deliberately never assigns $model->scope_signature on the
 * update path, so a caller who merely renames or reprioritizes a list
 * can never accidentally clobber a signature it has no way to compute
 * correctly itself.
 */
final class EloquentPriceListRepository implements PriceListRepository
{
    public function save(PriceList $priceList): void
    {
        $isNew = $priceList->id() === null;

        $model = $isNew
            ? new PriceListModel()
            : PriceListModel::findOrFail($priceList->id());

        $model->name = $priceList->name();
        $model->mode = $priceList->mode()->value;
        $model->percentage_basis_points = $priceList->percentageBasisPoints();
        $model->priority = $priceList->priority();
        $model->valid_from = $priceList->validFrom();
        $model->valid_until = $priceList->validUntil();
        $model->status = $priceList->status()->value;
        $model->is_system = $priceList->isSystem();

        if ($isNew) {
            $model->scope_signature = PriceListSignature::forUniversalScope()->value();
        }

        $model->save();

        if ($isNew) {
            $priceList->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?PriceList
    {
        $model = PriceListModel::find($id);

        return $model !== null ? $this->toDomainPriceList($model) : null;
    }

    public function existsActiveAtPriority(int $priority, ?string $excludingId = null): bool
    {
        $query = PriceListModel::where('priority', $priority)
            ->where('status', PriceListStatus::ACTIVE->value);

        if ($excludingId !== null) {
            $query->where('id', '!=', $excludingId);
        }

        return $query->exists();
    }

    public function findAllActiveAndValidAt(\DateTimeImmutable $at): array
    {
        return PriceListModel::where('status', PriceListStatus::ACTIVE->value)
            ->where(function ($query) use ($at) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $at);
            })
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PriceListModel $model) => $this->toDomainPriceList($model))
            ->all();
    }

    public function findSystemListByName(string $name): ?PriceList
    {
        $model = PriceListModel::where('is_system', true)
            ->where('name', $name)
            ->first();

        return $model !== null ? $this->toDomainPriceList($model) : null;
    }

    private function toDomainPriceList(PriceListModel $model): PriceList
    {
        return PriceList::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            mode: PriceListMode::from($model->mode),
            priority: $model->priority,
            validFrom: $model->valid_from?->toDateTimeImmutable(),
            validUntil: $model->valid_until?->toDateTimeImmutable(),
            percentageBasisPoints: $model->percentage_basis_points,
            status: PriceListStatus::from($model->status),
            isSystem: $model->is_system,
        );
    }
}
