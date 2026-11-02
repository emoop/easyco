<?php

namespace EasyCo\Media\Events;

/**
 * HB-4.1: a media asset has just been saved as READY (its variants exist). Dispatched by ProcessMediaAssetJob after the row is saved; the application listens to it (an HTML block whose background picture
 * this is must be recompiled, because its picture could not be resolved before). The id is the asset's id as the repository gives it.
 */
final class MediaAssetReady
{
    public function __construct(public readonly string $mediaAssetId)
    {
    }
}
