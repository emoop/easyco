<?php

namespace App\Media\Usage;

use Illuminate\Database\Eloquent\Builder;

/**
 * One place that can hold a media id (media-library-design.md §3). A provider answers three questions, and `counts()` / `excludeUsed()` must cost ONE query (or one read of a JSON setting) for a
 * whole page of ids, never one per picture. An extension registers its own provider through the `media.usages` filter hook (see MediaUsageRegistry).
 */
interface MediaUsageProvider
{
    /**
     * @param  list<int>  $ids
     * @return array<int, int> id => number of uses (only the ids that are used)
     */
    public function counts(array $ids): array;

    /** @return list<MediaUsage> every use of one picture, with a link to where it is edited */
    public function usagesOf(int $id): array;

    /** Narrows a query on `catalog_media` to the pictures THIS provider does not use (the «неизползвани» filter calls it once per provider). */
    public function excludeUsed(Builder $query): void;
}
