<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Season;

/**
 * Maps Season onto catalog_seasons via SeasonModel. Identical shape to
 * EloquentBrandRepository's id-or-new + assignId() pattern. A
 * duplicate `slug` is a plain unique-constraint violation that
 * propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent as every other simple Catalog lookup
 * entity's repository.
 */
final class EloquentSeasonRepository implements SeasonRepository
{
    public function save(Season $season): void
    {
        $model = $season->id() !== null
            ? SeasonModel::findOrFail($season->id())
            : new SeasonModel();

        $model->name = $season->name();
        $model->slug = $season->slug();
        $model->save();

        if ($season->id() === null) {
            $season->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Season
    {
        $model = SeasonModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Season[] */
    public function all(): array
    {
        return SeasonModel::all()
            ->map(fn (SeasonModel $model) => $this->toDomain($model))
            ->all();
    }

    public function countProductsUsing(string $seasonId): int
    {
        return ProductModel::where('season_id', $seasonId)->count();
    }

    private function toDomain(SeasonModel $model): Season
    {
        return new Season(
            id: (string) $model->id,
            name: $model->name,
            slug: $model->slug,
        );
    }
}
