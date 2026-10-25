<?php

namespace App\Media;

use EasyCo\Media\Exceptions\MediaLimitExceededException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Shared by the product pages (CreateProduct, EditProduct, EditVariableProduct) — the glue between the picker fields `main_photo_id` / `gallery_photo_ids` and ProductPhotoSync. A limit breach
 * is shown as the SAME notification the API's message gives and halts the save; the page's own DB::transaction() wrap (see CreateProduct's docblock: Filament's Halt rollback is a no-op in this
 * panel) undoes what was already written.
 */
trait SyncsProductPhotos
{
    /** @param  array<string, mixed>  $data  the form data */
    protected function syncProductPhotos(string $productId, array $data): void
    {
        $ids = [];

        if (filled($data['main_photo_id'] ?? null)) {
            $ids[] = (int) $data['main_photo_id'];
        }

        foreach ($data['gallery_photo_ids'] ?? [] as $id) {
            $ids[] = (int) $id;
        }

        try {
            app(ProductPhotoSync::class)->sync($productId, $ids);
            // the single video (ML-3 §3), after the photos, with its own guards
            app(ProductVideoSync::class)->sync($productId, $data['video_id'] ?? null, (bool) ($data['video_autoplay'] ?? false));
        } catch (MediaLimitExceededException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    /** Fills `main_photo_id` and `gallery_photo_ids` with the product's current photos. @param  array<string, mixed>  $data */
    protected function fillProductPhotos(string $productId, array $data): array
    {
        [$main, $gallery] = app(ProductPhotoSync::class)->current($productId);

        $data['main_photo_id'] = $main;
        $data['gallery_photo_ids'] = $gallery;
        [$data['video_id'], $data['video_autoplay']] = app(ProductVideoSync::class)->current($productId);

        return $data;
    }
}
