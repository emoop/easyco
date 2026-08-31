<?php

namespace EasyCo\Media\Persistence\Eloquent;

use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\VariationMedia;
use Illuminate\Database\QueryException;

/** Maps the VariationMedia entity onto catalog_variation_media. */
final class EloquentVariationMediaRepository implements VariationMediaRepository
{
    public function save(VariationMedia $variationMedia): void
    {
        $model = $variationMedia->id() !== null
            ? VariationMediaModel::findOrFail($variationMedia->id())
            : new VariationMediaModel();

        $model->variation_id = $variationMedia->variationId();
        $model->media_id = $variationMedia->mediaId();
        $model->sort_order = $variationMedia->sortOrder();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isVariationMediaUniqueViolation($e)) {
                throw MediaAlreadyAttachedException::forVariation(
                    $variationMedia->variationId(),
                    $variationMedia->mediaId()
                );
            }

            throw $e;
        }

        if ($variationMedia->id() === null) {
            $variationMedia->assignId((string) $model->id);
        }
    }

    /**
     * Detects a violation of catalog_variation_media_variation_id_media_id_unique
     * — the UNIQUE(variation_id, media_id) index from
     * 2026_08_23_000012_create_catalog_media_tables.php, confirmed via a
     * real SHOW CREATE TABLE against the dev database rather than
     * assumed. Same shared primary check as
     * EloquentProductMediaRepository::isProductMediaUniqueViolation() —
     * SQLSTATE 23000 + driver error code, then errorInfo[2] narrows to
     * this specific constraint (CLAUDE.md rule 3).
     */
    private function isVariationMediaUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_variation_media_variation_id_media_id_unique')
            || str_contains($driverErrorMessage, 'catalog_variation_media.media_id');
    }

    private function isPossibleUniqueConstraintViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverErrorCode, [1062, 19], true);
    }

    public function remove(string $id): void
    {
        VariationMediaModel::findOrFail($id)->delete();
    }

    /** @return VariationMedia[] */
    public function findByVariationId(string $variationId): array
    {
        return VariationMediaModel::where('variation_id', $variationId)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(fn (VariationMediaModel $model) => $this->toDomainVariationMedia($model))
            ->all();
    }

    public function countByVariationId(string $variationId): int
    {
        return VariationMediaModel::where('variation_id', $variationId)->count();
    }

    private function toDomainVariationMedia(VariationMediaModel $model): VariationMedia
    {
        return VariationMedia::reconstituteFromStorage(
            id: (string) $model->id,
            variationId: (string) $model->variation_id,
            mediaId: (string) $model->media_id,
            sortOrder: $model->sort_order,
        );
    }
}
