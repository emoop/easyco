<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Write-interception per admin-panel-design.md §5. */
class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $collection = app(CollectionRepository::class)->findById((string) $record->id);

        if ($collection === null) {
            throw new RuntimeException("Collection \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($collection->name() !== $data['name']) {
            $collection->rename($data['name']);
        }

        if ($collection->slug() !== $data['slug']) {
            $collection->changeSlug($data['slug']);
        }

        // CT-1.1: «Премахни изображението» sets the field to null and only detaches (the asset stays in «Медия», now unused); a save that does not touch it keeps the picture
        $picture = filled($data['picture_media_asset_id'] ?? null) ? (string) $data['picture_media_asset_id'] : null;

        if ($picture === null && $collection->pictureMediaAssetId() !== null) {
            $collection->removePicture();
        } elseif ($picture !== null && $picture !== $collection->pictureMediaAssetId()) {
            $collection->setPicture($picture);
        }

        app(CollectionRepository::class)->save($collection);

        return CollectionModel::find($collection->id());
    }
}
