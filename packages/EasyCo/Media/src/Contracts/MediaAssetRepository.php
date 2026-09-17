<?php

namespace EasyCo\Media\Contracts;

use EasyCo\Media\MediaAsset;

interface MediaAssetRepository
{
    /** Insert or update. */
    public function save(MediaAsset $asset): void;

    public function findById(string $id): ?MediaAsset;

    /**
     * A REAL, hard delete of the row — unlike ProductMediaRepository::
     * remove()/VariationMediaRepository's own detach-only posture, no
     * caller of this method may assume the underlying file(s) are also
     * gone; deleting the actual bytes from disk is the CALLER's job
     * (via MediaStorageAdapter::delete(), once per real file: the
     * asset's own path() plus each of its variants() paths) — this
     * method only ever removes the catalog_media row itself. Added for
     * the product-archive photo-cleanup task (App\Services\
     * ArchiveProductMediaCleaner) — the first real caller that needs
     * genuine deletion rather than detach.
     */
    public function delete(string $id): void;
}
