<?php

namespace EasyCo\Media\Contracts;

use EasyCo\Media\MediaAsset;

interface MediaAssetRepository
{
    /** Insert or update. */
    public function save(MediaAsset $asset): void;

    public function findById(string $id): ?MediaAsset;
}
